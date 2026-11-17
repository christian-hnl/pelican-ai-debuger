<?php

namespace ChristianHnl\AiDebugger\Jobs;

use ChristianHnl\AiDebugger\Enums\BackupStatus;
use ChristianHnl\AiDebugger\Enums\SessionStatus;
use ChristianHnl\AiDebugger\Enums\StepType;
use ChristianHnl\AiDebugger\Exceptions\SessionCancelledException;
use ChristianHnl\AiDebugger\Models\DebugSession;
use ChristianHnl\AiDebugger\Services\Agent\DebugAgent;
use ChristianHnl\AiDebugger\Services\Agent\ToolRegistry;
use ChristianHnl\AiDebugger\Services\BackupService;
use ChristianHnl\AiDebugger\Support\SessionNotifier;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Throwable;

/**
 * Runs a complete debug session: backup, agent loop, report.
 */
class RunDebugSessionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    // A session with big modpacks and several test starts can take a while
    public int $timeout = 14400;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $sessionId) {}

    public function handle(): void
    {
        // Claim the session atomically, a duplicate delivery of this job exits here
        $claimed = DebugSession::query()
            ->whereKey($this->sessionId)
            ->where('status', SessionStatus::Pending->value)
            ->update(['status' => SessionStatus::Preparing->value, 'started_at' => now()]);

        if (!$claimed) {
            return;
        }

        /** @var DebugSession $session */
        $session = DebugSession::query()->with('server')->findOrFail($this->sessionId);

        @set_time_limit(0);

        try {
            $this->run($session);
        } catch (SessionCancelledException) {
            $session->refresh();
            $session->update(['status' => SessionStatus::Cancelled, 'finished_at' => now()]);
            $session->log(StepType::Warning, trans('ai-debugger::strings.log.cancelled'));
        } catch (Throwable $exception) {
            report($exception);

            $session->refresh();
            $cancelled = $session->status === SessionStatus::Cancelling;

            $session->update([
                'status' => $cancelled ? SessionStatus::Cancelled : SessionStatus::Failed,
                'error' => mb_substr($exception->getMessage(), 0, 5000),
                'finished_at' => now(),
            ]);

            if ($session->backup_status === BackupStatus::Creating) {
                $session->update(['backup_status' => BackupStatus::Failed]);
            }

            $session->log($cancelled ? StepType::Warning : StepType::Error, trans($cancelled ? 'ai-debugger::strings.log.cancelled' : 'ai-debugger::strings.log.failed'), $exception->getMessage());
        }

        SessionNotifier::finished($session->refresh());
    }

    /**
     * @throws Throwable
     */
    protected function run(DebugSession $session): void
    {
        $server = $session->server;
        $tools = new ToolRegistry($session);
        $control = $tools->control();

        // 1. Preparing
        $session->log(StepType::Info, trans('ai-debugger::strings.log.preparing', ['server' => $server->name]));

        if (!is_null($server->status)) {
            throw new Exception(trans('ai-debugger::strings.notifications.server_busy') . ' (' . $server->status->value . ')');
        }

        $state = $control->state();
        $session->log(StepType::Info, trans('ai-debugger::strings.log.server_state', ['state' => $state]));

        if ($state !== 'offline') {
            $session->log(StepType::Info, trans('ai-debugger::strings.log.stopping'));
            $control->stopAndWait();
        }

        $this->assertNotCancelled($session);

        // 2. Backup
        $session->update(['status' => SessionStatus::BackingUp]);
        (new BackupService($session))->create();

        $this->assertNotCancelled($session);

        // 3. AI
        $session->update(['status' => SessionStatus::Analyzing]);
        $session->log(StepType::Info, trans('ai-debugger::strings.log.agent_start', ['model' => DebugAgent::model()]));

        $report = (new DebugAgent($session, $tools))->run();

        // 4. Finalize
        $session->update(['status' => SessionStatus::Finalizing]);

        $works = ($tools->lastTestStart['result'] ?? null) === 'started';

        if ($session->option('leave_running', false) && $works && $session->option('allow_test_starts', true)) {
            $session->log(StepType::Info, trans('ai-debugger::strings.log.starting'));
            $control->power('start');
        }

        if ($report === null) {
            $session->update([
                'status' => SessionStatus::Failed,
                'error' => trans('ai-debugger::strings.log.no_report'),
                'finished_at' => now(),
            ]);
            $session->log(StepType::Error, trans('ai-debugger::strings.log.no_report'));

            return;
        }

        $report['server_starts'] = $works || (bool) ($report['server_starts'] ?? false);
        $report['tested'] = $tools->lastTestStart !== null;
        $report['changes'] = $session->changes()->count();

        $session->update([
            'status' => SessionStatus::Completed,
            'summary' => $report['summary'],
            'report' => $report,
            'finished_at' => now(),
        ]);

        $session->log(StepType::Success, trans('ai-debugger::strings.log.completed'));
    }

    protected function assertNotCancelled(DebugSession $session): void
    {
        if ($session->isCancelRequested()) {
            throw new SessionCancelledException();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $session = DebugSession::query()->find($this->sessionId);
        if (!$session) {
            return;
        }

        // A duplicate delivery that ran out of attempts while the first one is still working
        if ($exception instanceof MaxAttemptsExceededException && $session->status->isActive() && $session->status !== SessionStatus::Pending) {
            return;
        }

        if ($session->status->isActive()) {
            $session->update([
                'status' => SessionStatus::Failed,
                'error' => $exception instanceof TimeoutExceededException ? trans('ai-debugger::strings.log.timeout') : mb_substr((string) $exception?->getMessage(), 0, 5000),
                'finished_at' => now(),
            ]);

            if ($session->backup_status === BackupStatus::Creating) {
                $session->update(['backup_status' => BackupStatus::Failed]);
            }

            $session->log(StepType::Error, trans('ai-debugger::strings.log.failed'), $exception?->getMessage());
        }
    }
}
