<?php

namespace ChristianHnl\AiDebugger\Console\Commands;

use ChristianHnl\AiDebugger\Enums\SessionStatus;
use ChristianHnl\AiDebugger\Jobs\RunDebugSessionJob;
use ChristianHnl\AiDebugger\Models\DebugSession;
use Illuminate\Console\Command;

class RunSessionCommand extends Command
{
    protected $signature = 'ai-debugger:run {session : Session id or uuid}';

    protected $description = 'Runs a pending AI Debugger session in the foreground (for setups without a queue worker or for debugging).';

    public function handle(): int
    {
        $key = (string) $this->argument('session');

        $session = DebugSession::query()->where('uuid', $key)->orWhere('id', is_numeric($key) ? (int) $key : 0)->first();

        if (!$session) {
            $this->error('Session not found.');

            return self::FAILURE;
        }

        if ($session->status !== SessionStatus::Pending) {
            $this->error('Only pending sessions can be run, this one is ' . $session->status->value . '.');

            return self::FAILURE;
        }

        $this->info("Running session {$session->uuid} for server {$session->server->name} ...");

        (new RunDebugSessionJob($session->id))->handle();

        $session->refresh();
        $this->info('Finished with status: ' . $session->status->value);

        if ($session->summary) {
            $this->line($session->summary);
        }

        return $session->status === SessionStatus::Completed ? self::SUCCESS : self::FAILURE;
    }
}
