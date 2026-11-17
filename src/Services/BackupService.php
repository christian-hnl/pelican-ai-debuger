<?php

namespace ChristianHnl\AiDebugger\Services;

use App\Enums\ServerState;
use App\Exceptions\Service\Backup\TooManyBackupsException;
use App\Models\Backup;
use App\Models\Server;
use App\Repositories\Daemon\DaemonBackupRepository;
use App\Services\Backups\DeleteBackupService;
use App\Services\Backups\DownloadLinkService;
use App\Services\Backups\InitiateBackupService;
use ChristianHnl\AiDebugger\Enums\BackupStatus;
use ChristianHnl\AiDebugger\Enums\BackupType;
use ChristianHnl\AiDebugger\Enums\StepType;
use ChristianHnl\AiDebugger\Models\DebugSession;
use Exception;
use Throwable;

/**
 * Creates, restores and deletes the backup that is taken before the AI touches anything.
 *
 * Two backup types exist:
 *  - panel:   a regular Pelican backup (needs a free backup slot on the server)
 *  - archive: a tar.gz inside the server files at /.ai-debugger/backups (works without backup slots)
 */
class BackupService
{
    public const BACKUP_DIR = ServerFiles::PLUGIN_DIR . '/backups';

    protected ServerFiles $files;

    protected ServerControl $control;

    public function __construct(protected DebugSession $session)
    {
        $this->files = new ServerFiles($session->server);
        $this->control = new ServerControl($session->server);
    }

    protected function server(): Server
    {
        return $this->session->server;
    }

    /**
     * @throws Throwable
     */
    public function create(): void
    {
        $mode = (string) config('ai-debugger.backup_mode', 'auto');

        $this->session->update(['backup_status' => BackupStatus::Creating]);

        try {
            if ($mode === 'panel' || ($mode === 'auto' && $this->hasFreePanelSlot())) {
                try {
                    $this->createPanelBackup();

                    return;
                } catch (TooManyBackupsException $exception) {
                    if ($mode === 'panel') {
                        throw $exception;
                    }

                    $this->session->log(StepType::Warning, trans('ai-debugger::strings.log.backup_no_slot'));
                }
            }

            $this->createArchiveBackup();
        } catch (Throwable $exception) {
            $this->session->update(['backup_status' => BackupStatus::Failed]);

            throw $exception;
        }
    }

    protected function hasFreePanelSlot(): bool
    {
        $server = $this->server();

        return $server->backup_limit > 0 && $server->backups()->nonFailed()->count() < $server->backup_limit;
    }

    /**
     * @throws Throwable
     */
    protected function createPanelBackup(): void
    {
        $this->session->log(StepType::Info, trans('ai-debugger::strings.log.backup_panel_start'));

        /** @var InitiateBackupService $service */
        $service = app(InitiateBackupService::class);
        // Never pass override = true, that would delete the oldest backup of the user
        $backup = $service->setIsLocked(true)->handle($this->server(), 'AI Debugger ' . $this->session->shortId() . ' ' . now()->format('Y-m-d H:i'));

        $this->session->update([
            'backup_type' => BackupType::Panel,
            'backup_reference' => $backup->uuid,
        ]);

        $deadline = now()->addMinutes(max(1, (int) config('ai-debugger.backup_timeout', 60)));
        while (now()->lessThan($deadline)) {
            sleep(5);
            $backup->refresh();

            if ($backup->completed_at) {
                break;
            }

            if ($this->session->isCancelRequested()) {
                throw new Exception(trans('ai-debugger::strings.log.cancelled'));
            }
        }

        if (!$backup->completed_at) {
            throw new Exception(trans('ai-debugger::strings.log.backup_timeout'));
        }

        if (!$backup->is_successful) {
            throw new Exception(trans('ai-debugger::strings.log.backup_panel_failed'));
        }

        $this->session->update([
            'backup_status' => BackupStatus::Ready,
            'backup_size' => $backup->bytes,
        ]);

        $this->session->log(StepType::Success, trans('ai-debugger::strings.log.backup_ready', ['size' => self::formatBytes($backup->bytes)]), null, ['backup' => $backup->uuid]);
    }

    /**
     * @throws Exception
     */
    protected function createArchiveBackup(): void
    {
        $this->session->log(StepType::Info, trans('ai-debugger::strings.log.backup_archive_start'));

        // Files must not change while they are archived
        if (!$this->control->isOffline()) {
            $this->control->stopAndWait();
        }

        $entries = array_values(array_filter(
            $this->files->list(''),
            fn (array $entry) => $entry['name'] !== ServerFiles::PLUGIN_DIR,
        ));

        if (empty($entries)) {
            throw new Exception(trans('ai-debugger::strings.log.backup_empty'));
        }

        $this->files->makeDirectory(self::BACKUP_DIR);

        $name = 'backup-' . now()->format('Ymd-His') . '-' . $this->session->shortId();
        $archive = $this->files->compress('', array_column($entries, 'name'), $name);

        $target = ServerFiles::join(self::BACKUP_DIR, ServerFiles::basename($archive));
        $this->files->move($archive, $target);

        $size = $this->files->stat($target)['size'] ?? null;

        $this->session->update([
            'backup_type' => BackupType::Archive,
            'backup_reference' => $target,
            'backup_status' => BackupStatus::Ready,
            'backup_size' => $size,
        ]);

        $this->session->log(StepType::Success, trans('ai-debugger::strings.log.backup_ready', ['size' => self::formatBytes((int) $size)]), null, ['backup' => '/' . $target]);
    }

    /**
     * Restores the backup. Archive restores run synchronously, panel restores are handed to the daemon.
     *
     * @throws Throwable
     */
    public function restore(): void
    {
        $session = $this->session;

        if (!$session->backup_status->canRestore() || !$session->backup_reference) {
            throw new Exception(trans('ai-debugger::strings.notifications.restore_unavailable'));
        }

        if (!is_null($this->server()->status)) {
            throw new Exception(trans('ai-debugger::strings.notifications.server_busy'));
        }

        $session->update(['backup_status' => BackupStatus::Restoring]);
        $session->log(StepType::Info, trans('ai-debugger::strings.log.restore_start'));

        try {
            $this->control->stopAndWait();

            match ($session->backup_type) {
                BackupType::Panel => $this->restorePanelBackup(),
                default => $this->restoreArchiveBackup(),
            };

            // Everything the agent changed is undone by the restore
            $session->changes()->update(['reverted' => true]);

            $session->update(['backup_status' => BackupStatus::Restored]);
            $session->log(StepType::Success, trans('ai-debugger::strings.log.restore_done'));
        } catch (Throwable $exception) {
            $session->update(['backup_status' => BackupStatus::RestoreFailed]);
            $session->log(StepType::Error, trans('ai-debugger::strings.log.restore_failed'), $exception->getMessage());

            throw $exception;
        }
    }

    /**
     * @throws Throwable
     */
    protected function restorePanelBackup(): void
    {
        $server = $this->server();

        /** @var Backup|null $backup */
        $backup = $server->backups()->where('uuid', $this->session->backup_reference)->first();
        if (!$backup || !$backup->is_successful) {
            throw new Exception(trans('ai-debugger::strings.log.backup_missing'));
        }

        $user = $this->session->user ?? $server->user;
        $url = app(DownloadLinkService::class)->handle($backup, $user);

        $server->update(['status' => ServerState::RestoringBackup]);

        try {
            app(DaemonBackupRepository::class)->setServer($server)->restore($backup, $url, true);
        } catch (Throwable $exception) {
            $server->update(['status' => null]);

            throw $exception;
        }

        // The daemon reports back to the panel when it is done, wait for that
        $deadline = now()->addMinutes(max(1, (int) config('ai-debugger.backup_timeout', 60)));
        while (now()->lessThan($deadline)) {
            sleep(5);

            if (is_null($server->fresh()?->status)) {
                return;
            }
        }

        throw new Exception(trans('ai-debugger::strings.log.restore_timeout'));
    }

    /**
     * @throws Exception
     */
    protected function restoreArchiveBackup(): void
    {
        $archive = (string) $this->session->backup_reference;

        if (!$this->files->exists($archive)) {
            throw new Exception(trans('ai-debugger::strings.log.backup_missing'));
        }

        // Remove everything except our own folder, then unpack the archive into the root
        $entries = array_values(array_filter(
            $this->files->list(''),
            fn (array $entry) => $entry['name'] !== ServerFiles::PLUGIN_DIR,
        ));

        foreach (array_chunk(array_column($entries, 'name'), 50) as $chunk) {
            $this->files->delete($chunk);
        }

        $this->files->decompress('', $archive);
    }

    /**
     * @throws Throwable
     */
    public function delete(): void
    {
        $session = $this->session;

        if ($session->backup_reference) {
            if ($session->backup_type === BackupType::Panel) {
                $backup = $this->server()->backups()->where('uuid', $session->backup_reference)->first();
                if ($backup) {
                    // The backup was locked so the panel does not rotate it away, unlock it first
                    $backup->update(['is_locked' => false]);
                    app(DeleteBackupService::class)->handle($backup);
                }
            } elseif ($this->files->exists($session->backup_reference)) {
                $this->files->delete([$session->backup_reference]);
            }
        }

        $session->update(['backup_status' => BackupStatus::Deleted]);
        $session->log(StepType::Info, trans('ai-debugger::strings.log.backup_deleted'));
    }

    public static function formatBytes(?int $bytes): string
    {
        $bytes = (int) $bytes;
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $i = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, $i === 0 ? 0 : 1) . ' ' . $units[$i];
    }
}
