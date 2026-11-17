<?php

namespace ChristianHnl\AiDebugger\Jobs;

use ChristianHnl\AiDebugger\Enums\BackupStatus;
use ChristianHnl\AiDebugger\Models\DebugSession;
use ChristianHnl\AiDebugger\Services\BackupService;
use ChristianHnl\AiDebugger\Support\SessionNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Throwable;

class RestoreBackupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 7200;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $sessionId) {}

    public function handle(): void
    {
        $session = DebugSession::query()->with('server')->find($this->sessionId);

        if (!$session || $session->backup_status !== BackupStatus::Restoring) {
            return;
        }

        @set_time_limit(0);

        // BackupService::restore needs a restorable state, the UI already switched it to "restoring"
        $session->update(['backup_status' => BackupStatus::Ready]);

        try {
            (new BackupService($session))->restore();
            SessionNotifier::restored($session, true);
        } catch (Throwable $exception) {
            report($exception);
            SessionNotifier::restored($session, false, $exception->getMessage());
        }
    }

    public function failed(?Throwable $exception): void
    {
        // A second delivery of a job that is still running (queue retry_after), the first one goes on
        if ($exception instanceof MaxAttemptsExceededException) {
            return;
        }

        $session = DebugSession::query()->find($this->sessionId);

        if ($session && $session->backup_status === BackupStatus::Restoring) {
            $session->update(['backup_status' => BackupStatus::RestoreFailed]);
        }
    }
}
