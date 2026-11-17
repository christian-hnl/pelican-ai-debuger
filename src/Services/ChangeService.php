<?php

namespace ChristianHnl\AiDebugger\Services;

use ChristianHnl\AiDebugger\Enums\ChangeAction;
use ChristianHnl\AiDebugger\Enums\StepType;
use ChristianHnl\AiDebugger\Models\DebugChange;
use ChristianHnl\AiDebugger\Models\DebugSession;
use Exception;
use Illuminate\Support\Str;

/**
 * Executes file changes for a session and records them so each one can be reverted individually.
 */
class ChangeService
{
    public const QUARANTINE_DIR = ServerFiles::PLUGIN_DIR . '/quarantine';

    // Larger files are not stored for an undo, the agent may only overwrite smaller files
    public const MAX_WRITE_SIZE = 2 * 1024 * 1024;

    public function __construct(protected DebugSession $session, protected ServerFiles $files) {}

    public function quarantinePath(string $path): string
    {
        return ServerFiles::join(self::QUARANTINE_DIR, $this->session->shortId(), ServerFiles::normalize($path));
    }

    /**
     * Moves a file into the quarantine folder (a reversible "delete").
     *
     * @throws Exception
     */
    public function quarantine(string $path, ?string $reason = null): DebugChange
    {
        $path = ServerFiles::assertWritable($path);

        if (!$this->files->exists($path)) {
            throw new Exception("/$path does not exist.");
        }

        $target = $this->quarantinePath($path);
        if ($this->files->exists($target)) {
            $target .= '.' . Str::lower(Str::random(6));
        }

        $this->files->move($path, $target);

        return $this->record(ChangeAction::Quarantine, $path, $target, $reason);
    }

    /**
     * @throws Exception
     */
    public function write(string $path, string $content, ?string $reason = null): DebugChange
    {
        $path = ServerFiles::assertWritable($path);

        $stat = $this->files->stat($path);
        $previous = null;

        if ($stat) {
            if ($stat['directory']) {
                throw new Exception("/$path is a directory.");
            }

            if ($stat['size'] > self::MAX_WRITE_SIZE) {
                throw new Exception("/$path is larger than 2 MiB and can not be edited safely.");
            }

            $previous = $this->files->read($path);
        } else {
            $this->files->makeDirectory(ServerFiles::dirname($path));
        }

        $this->files->write($path, $content);

        return $this->record($stat ? ChangeAction::Write : ChangeAction::Create, $path, null, $reason, $previous, [
            'bytes' => strlen($content),
            'previous_bytes' => $previous !== null ? strlen($previous) : null,
        ]);
    }

    /**
     * @throws Exception
     */
    public function move(string $from, string $to, ?string $reason = null): DebugChange
    {
        $from = ServerFiles::assertWritable($from);
        $to = ServerFiles::assertWritable($to);

        if (!$this->files->exists($from)) {
            throw new Exception("/$from does not exist.");
        }

        if ($this->files->exists($to)) {
            throw new Exception("/$to already exists.");
        }

        $this->files->move($from, $to);

        return $this->record(ChangeAction::Move, $from, $to, $reason);
    }

    /**
     * Replaces a mod jar with a newer file downloaded from an url. The old jar goes into quarantine.
     *
     * @param  array<string, mixed>  $meta
     *
     * @throws Exception
     */
    public function replaceWithDownload(string $path, string $url, string $filename, ?string $reason = null, array $meta = []): DebugChange
    {
        $path = ServerFiles::assertWritable($path);
        $folder = ServerFiles::dirname($path);
        $filename = ServerFiles::basename($filename);
        $newPath = ServerFiles::join($folder, $filename);
        ServerFiles::assertWritable($newPath);

        if (!$this->files->exists($path)) {
            throw new Exception("/$path does not exist.");
        }

        // Download under a temporary name first so a failed download does not lose the old mod
        $temporary = $filename . '.ai-download';
        $this->files->pull($url, $folder, $temporary);

        $target = $this->quarantinePath($path);
        if ($this->files->exists($target)) {
            $target .= '.' . Str::lower(Str::random(6));
        }

        $this->files->move($path, $target);

        if ($this->files->exists($newPath)) {
            // Same file name as the old one was moved away already, any other existing file is a duplicate
            $this->files->delete([$newPath]);
        }

        $this->files->move(ServerFiles::join($folder, $temporary), $newPath);

        return $this->record(ChangeAction::Update, $path, $target, $reason, null, [...$meta, 'new_path' => $newPath, 'url' => $url]);
    }

    /**
     * Downloads a new file (e.g. a missing dependency) into a folder.
     *
     * @param  array<string, mixed>  $meta
     *
     * @throws Exception
     */
    public function install(string $folder, string $url, string $filename, ?string $reason = null, array $meta = []): DebugChange
    {
        $path = ServerFiles::assertWritable(ServerFiles::join($folder, ServerFiles::basename($filename)));

        if ($this->files->exists($path)) {
            throw new Exception("/$path already exists.");
        }

        $this->files->pull($url, ServerFiles::dirname($path), ServerFiles::basename($path));

        return $this->record(ChangeAction::Install, $path, null, $reason, null, [...$meta, 'url' => $url]);
    }

    /**
     * Reverts a single change.
     *
     * @throws Exception
     */
    public function revert(DebugChange $change): void
    {
        if (!$change->canRevert()) {
            throw new Exception(trans('ai-debugger::strings.notifications.revert_unavailable'));
        }

        switch ($change->action) {
            case ChangeAction::Quarantine:
            case ChangeAction::Move:
                if ($this->files->exists($change->path)) {
                    throw new Exception("/$change->path exists again, remove it first.");
                }

                $this->files->move((string) $change->target, $change->path);

                break;

            case ChangeAction::Write:
                $this->files->write($change->path, (string) $change->previous_content);

                break;

            case ChangeAction::Create:
            case ChangeAction::Install:
                if ($this->files->exists($change->path)) {
                    $this->files->delete([$change->path]);
                }

                break;

            case ChangeAction::Update:
                $newPath = $change->meta['new_path'] ?? null;
                if ($newPath && $this->files->exists($newPath)) {
                    $this->files->delete([$newPath]);
                }

                $this->files->move((string) $change->target, $change->path);

                break;

            case ChangeAction::Delete:
                throw new Exception(trans('ai-debugger::strings.notifications.revert_unavailable'));
        }

        $change->update(['reverted' => true]);

        $this->session->log(StepType::Info, trans('ai-debugger::strings.log.change_reverted', [
            'action' => $change->action->getLabel(),
            'path' => '/' . $change->path,
        ]));
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    protected function record(ChangeAction $action, string $path, ?string $target, ?string $reason, ?string $previous = null, ?array $meta = null): DebugChange
    {
        /** @var DebugChange $change */
        $change = $this->session->changes()->create([
            'action' => $action,
            'path' => $path,
            'target' => $target,
            'reason' => $reason ? Str::limit($reason, 2000) : null,
            'previous_content' => $previous,
            'meta' => $meta ? array_filter($meta, fn ($value) => $value !== null) : null,
        ]);

        $this->session->log(StepType::Change, $action->getLabel() . ': /' . $path, $reason, array_filter([
            'action' => $action->value,
            'path' => '/' . $path,
            'target' => $target ? '/' . $target : null,
        ]));

        return $change;
    }
}
