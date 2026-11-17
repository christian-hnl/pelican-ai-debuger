<?php

namespace ChristianHnl\AiDebugger\Services;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Models\User;
use ChristianHnl\AiDebugger\Enums\BackupStatus;
use ChristianHnl\AiDebugger\Enums\SessionStatus;
use ChristianHnl\AiDebugger\Enums\StepType;
use ChristianHnl\AiDebugger\Jobs\RestoreBackupJob;
use ChristianHnl\AiDebugger\Jobs\RunDebugSessionJob;
use ChristianHnl\AiDebugger\Models\DebugChange;
use ChristianHnl\AiDebugger\Models\DebugSession;
use ChristianHnl\AiDebugger\Services\Agent\DebugAgent;
use Exception;
use Illuminate\Support\Facades\DB;

class DebugSessionService
{
    /** Session options and their defaults */
    public const OPTIONS = [
        'remove_client_mods' => true,
        'update_mods' => true,
        'install_missing_dependencies' => true,
        'edit_configs' => true,
        'allow_test_starts' => true,
        'accept_eula' => false,
        'leave_running' => false,
    ];

    /** Subuser permissions a server user needs to use the debugger on a server */
    public const SERVER_PERMISSIONS = [
        SubuserPermission::FileRead,
        SubuserPermission::FileReadContent,
        SubuserPermission::FileCreate,
        SubuserPermission::FileUpdate,
        SubuserPermission::FileDelete,
        SubuserPermission::FileArchive,
        SubuserPermission::ControlStart,
        SubuserPermission::ControlStop,
    ];

    public static function isAdmin(?User $user, string $ability = 'view'): bool
    {
        return $user !== null && $user->can($ability . ' aiDebugger');
    }

    /**
     * Whether the user may use the debugger on this server from the server panel.
     */
    public static function canUseOnServer(?User $user, Server $server, string $ability = 'create'): bool
    {
        if (!$user) {
            return false;
        }

        if (self::isAdmin($user, $ability)) {
            return true;
        }

        if (!config('ai-debugger.allow_server_users', false)) {
            return false;
        }

        foreach (self::SERVER_PERMISSIONS as $permission) {
            if (!$user->can($permission, $server)) {
                return false;
            }
        }

        return true;
    }

    public static function activeSession(Server $server): ?DebugSession
    {
        return DebugSession::query()->where('server_id', $server->id)->active()->latest('id')->first();
    }

    public static function remainingToday(User $user, Server $server): ?int
    {
        if (self::isAdmin($user, 'create')) {
            return null;
        }

        $used = DebugSession::query()
            ->where('server_id', $server->id)
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        return max(0, (int) config('ai-debugger.user_daily_limit', 3) - $used);
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @throws Exception
     */
    public function start(Server $server, User $user, array $options = [], ?string $instructions = null): DebugSession
    {
        if (!DebugAgent::isConfigured()) {
            throw new Exception(trans('ai-debugger::strings.notifications.no_api_key'));
        }

        if (!is_null($server->status)) {
            throw new Exception(trans('ai-debugger::strings.notifications.server_busy'));
        }

        $remaining = self::remainingToday($user, $server);
        if ($remaining !== null && $remaining <= 0) {
            throw new Exception(trans('ai-debugger::strings.notifications.daily_limit', ['limit' => config('ai-debugger.user_daily_limit', 3)]));
        }

        $normalized = [];
        foreach (self::OPTIONS as $key => $default) {
            $normalized[$key] = (bool) ($options[$key] ?? $default);
        }
        $normalized['language'] = str_starts_with((string) ($user->language ?? app()->getLocale()), 'de') ? 'de' : 'en';

        $session = DB::transaction(function () use ($server, $user, $normalized, $instructions) {
            // Lock the server row so two clicks can not start two sessions
            Server::query()->whereKey($server->id)->lockForUpdate()->first();

            if (self::activeSession($server)) {
                throw new Exception(trans('ai-debugger::strings.notifications.already_running'));
            }

            return DebugSession::create([
                'server_id' => $server->id,
                'user_id' => $user->id,
                'status' => SessionStatus::Pending,
                'options' => $normalized,
                'instructions' => filled($instructions) ? mb_substr(trim($instructions), 0, 4000) : null,
                'model' => DebugAgent::model(),
            ]);
        });

        $session->log(StepType::Info, trans('ai-debugger::strings.log.queued'));

        RunDebugSessionJob::dispatch($session->id)->onQueue(config('ai-debugger.queue', 'default'));

        return $session;
    }

    public function cancel(DebugSession $session): void
    {
        // Not picked up by a worker yet, the job will skip it
        $cancelled = DebugSession::query()
            ->whereKey($session->id)
            ->where('status', SessionStatus::Pending->value)
            ->update(['status' => SessionStatus::Cancelled->value, 'finished_at' => now()]);

        // A worker that died (deploy, OOM, ...) never confirms the cancellation, force it after a while
        if (!$cancelled && $session->status === SessionStatus::Cancelling && $session->updated_at?->lt(now()->subMinutes(30))) {
            $session->update(['status' => SessionStatus::Cancelled, 'finished_at' => now(), 'error' => trans('ai-debugger::strings.log.worker_lost')]);
            if ($session->backup_status === BackupStatus::Creating) {
                $session->update(['backup_status' => BackupStatus::Failed]);
            }

            return;
        }

        if (!$cancelled) {
            DebugSession::query()
                ->whereKey($session->id)
                ->whereIn('status', array_map(fn ($status) => $status->value, SessionStatus::active()))
                ->update(['status' => SessionStatus::Cancelling->value]);
        }

        $session->refresh();
        $session->log(StepType::Warning, trans('ai-debugger::strings.log.cancel_requested'));
    }

    /**
     * @throws Exception
     */
    public function restore(DebugSession $session): void
    {
        if ($session->status->isActive()) {
            throw new Exception(trans('ai-debugger::strings.notifications.still_running'));
        }

        // A restore whose worker died stays "restoring", allow a new attempt after a while
        $stuck = $session->backup_status === BackupStatus::Restoring && $session->updated_at?->lt(now()->subHours(2));

        if (!$session->backup_status->canRestore() && !$stuck) {
            throw new Exception(trans('ai-debugger::strings.notifications.restore_unavailable'));
        }

        if (self::activeSession($session->server)) {
            throw new Exception(trans('ai-debugger::strings.notifications.already_running'));
        }

        $session->update(['backup_status' => BackupStatus::Restoring]);

        RestoreBackupJob::dispatch($session->id)->onQueue(config('ai-debugger.queue', 'default'));
    }

    /**
     * @throws \Throwable
     */
    public function deleteBackup(DebugSession $session): void
    {
        if ($session->status->isActive() || !$session->backup_status->canDelete()) {
            throw new Exception(trans('ai-debugger::strings.notifications.delete_unavailable'));
        }

        (new BackupService($session))->delete();
    }

    /**
     * @throws Exception
     */
    public function revert(DebugChange $change): void
    {
        $session = $change->session;

        if ($session->status->isActive()) {
            throw new Exception(trans('ai-debugger::strings.notifications.still_running'));
        }

        (new ChangeService($session, new ServerFiles($session->server)))->revert($change);
    }

    /**
     * Deletes a finished session including its backup and its quarantine folder.
     *
     * @throws \Throwable
     */
    public function delete(DebugSession $session): void
    {
        if ($session->status->isActive()) {
            throw new Exception(trans('ai-debugger::strings.notifications.still_running'));
        }

        if ($session->backup_status->canDelete()) {
            try {
                (new BackupService($session))->delete();
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        try {
            $files = new ServerFiles($session->server);
            $quarantine = ServerFiles::join(ChangeService::QUARANTINE_DIR, $session->shortId());
            if ($files->exists($quarantine)) {
                $files->delete([$quarantine]);
            }
        } catch (\Throwable $exception) {
            report($exception);
        }

        $session->delete();
    }
}
