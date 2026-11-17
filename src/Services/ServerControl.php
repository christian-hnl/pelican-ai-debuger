<?php

namespace ChristianHnl\AiDebugger\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use Exception;
use Illuminate\Support\Facades\Http;

/**
 * Power control, state and console access for a server.
 */
class ServerControl
{
    // Lines in the console that mean the server finished starting.
    protected const SUCCESS_PATTERNS = [
        '/Done \(\d+[.,]?\d*s\)!/i',
        '/For help, type "help"/i',
        '/Server started\.?$/i',
    ];

    // Lines in the console that mean the server crashed while starting.
    protected const CRASH_PATTERNS = [
        '/---- Minecraft Crash Report ----/',
        '/This crash report has been saved to/i',
        '/Crash report saved to/i',
        '/Mod loading has failed/i',
        '/ModLoadingException/',
        '/Failed to (start|load) the minecraft server/i',
        '/Encountered an unexpected exception/i',
        '/LoadingFailedException/',
        '/Incompatible mods? found!?/i',
        '/Could not execute entrypoint stage/i',
        '/Error: Could not find or load main class/i',
        '/Error occurred during initialization of VM/i',
        '/A fatal error has been detected by the Java Runtime Environment/i',
        '/java\.lang\.OutOfMemoryError/',
        '/Missing or unsupported mandatory dependencies/i',
        '/Attempted to load class .* for invalid dist/i',
        '/You need to agree to the EULA/i',
    ];

    public function __construct(protected Server $server) {}

    protected function repository(): DaemonServerRepository
    {
        return app(DaemonServerRepository::class)->setServer($this->server);
    }

    /**
     * @return string running, starting, stopping, offline or missing
     */
    public function state(): string
    {
        $details = $this->repository()->getDetails(10);

        return (string) ($details['state'] ?? 'missing');
    }

    /**
     * @return array<string, mixed>
     */
    public function utilization(): array
    {
        $details = $this->repository()->getDetails(10);

        return is_array($details['utilization'] ?? null) ? $details['utilization'] : [];
    }

    public function isOffline(): bool
    {
        return in_array($this->state(), ['offline', 'missing'], true);
    }

    /**
     * @throws Exception
     */
    public function power(string $action): void
    {
        if (!in_array($action, ['start', 'stop', 'restart', 'kill'], true)) {
            throw new Exception("Unknown power action '$action'");
        }

        $this->repository()->power($action);
    }

    /**
     * Stops the server and waits until it is offline. Kills it if it doesn't stop in time.
     *
     * @throws Exception
     */
    public function stopAndWait(int $timeout = 90): void
    {
        if ($this->isOffline()) {
            return;
        }

        $this->power('stop');

        $deadline = time() + $timeout;
        while (time() < $deadline) {
            sleep(3);

            if ($this->isOffline()) {
                return;
            }
        }

        $this->power('kill');

        $deadline = time() + 30;
        while (time() < $deadline) {
            sleep(2);

            if ($this->isOffline()) {
                return;
            }
        }

        throw new Exception('The server did not stop in time.');
    }

    /**
     * Returns the last lines of the console (the daemon keeps ~100 lines).
     *
     * @return string[]
     */
    public function console(int $lines = 100): array
    {
        try {
            $logs = Http::daemon($this->server->node)
                ->timeout(15)
                ->get("/api/servers/{$this->server->uuid}/logs", ['size' => max(1, min($lines, 100))])
                ->throw()
                ->json('data');
        } catch (Exception $exception) {
            report($exception);

            return [];
        }

        if (is_string($logs)) {
            $logs = explode("\n", $logs);
        }

        return array_map(fn ($line) => self::stripAnsi((string) $line), is_array($logs) ? $logs : []);
    }

    public static function stripAnsi(string $line): string
    {
        return (string) preg_replace('/\x1b\[[0-9;?]*[ -\/]*[@-~]/', '', $line);
    }

    /**
     * @param  string[]  $lines
     */
    public static function findMatch(array $lines, array $patterns): ?string
    {
        foreach ($lines as $line) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line)) {
                    return $line;
                }
            }
        }

        return null;
    }

    /**
     * Starts the server and watches it until it is up, crashed or the timeout is reached.
     *
     * @param  callable(string): void|null  $progress
     * @return array{result: string, seconds: int, state: string, matched_line: ?string, console: string[]}
     *
     * @throws Exception
     */
    public function testStart(int $timeout, ?callable $progress = null): array
    {
        $this->stopAndWait();

        $started = time();
        $this->power('start');

        $seenRunning = false;
        $result = 'timeout';
        $matched = null;
        $state = 'starting';
        $console = [];

        $deadline = $started + $timeout;
        while (time() < $deadline) {
            sleep(5);

            $state = $this->state();
            $console = $this->console(100);

            if (in_array($state, ['running', 'starting'], true)) {
                $seenRunning = true;
            }

            // Until the container is up the daemon may still return the output of the previous run
            if (!$seenRunning && time() - $started < 20) {
                continue;
            }

            if ($matched = self::findMatch($console, self::CRASH_PATTERNS)) {
                $result = 'crashed';

                break;
            }

            if ($matched = self::findMatch($console, self::SUCCESS_PATTERNS)) {
                $result = 'started';

                break;
            }

            // Offline again without a success message = it stopped/crashed
            if (in_array($state, ['offline', 'missing'], true) && ($seenRunning || time() - $started > 20)) {
                $result = 'stopped';

                break;
            }

            if ($progress) {
                $progress($state);
            }
        }

        // Give the server a moment to finish writing crash reports/logs
        if ($result !== 'started') {
            sleep(3);
            $console = $this->console(100) ?: $console;
        }

        return [
            'result' => $result,
            'seconds' => time() - $started,
            'state' => $state,
            'matched_line' => $matched,
            'console' => $console,
        ];
    }
}
