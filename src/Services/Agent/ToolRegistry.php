<?php

namespace ChristianHnl\AiDebugger\Services\Agent;

use ChristianHnl\AiDebugger\Enums\StepType;
use ChristianHnl\AiDebugger\Models\DebugSession;
use ChristianHnl\AiDebugger\Services\ChangeService;
use ChristianHnl\AiDebugger\Services\Mods\ModAnalyzer;
use ChristianHnl\AiDebugger\Services\Mods\ModrinthClient;
use ChristianHnl\AiDebugger\Services\ServerControl;
use ChristianHnl\AiDebugger\Services\ServerEnvironment;
use ChristianHnl\AiDebugger\Services\ServerFiles;
use Exception;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * The tools Claude can use on the server, plus their execution.
 */
class ToolRegistry
{
    // Longest tool result sent back to the model (characters)
    public const MAX_RESULT = 40_000;

    // Files that are read without downloading them first
    protected const DIRECT_READ_LIMIT = 256 * 1024;

    protected const BINARY_EXTENSIONS = ['jar', 'zip', 'gz', 'tgz', 'tar', 'rar', '7z', 'dat', 'dat_old', 'mca', 'mcr', 'nbt', 'png', 'jpg', 'jpeg', 'gif', 'ogg', 'class', 'so', 'dll', 'exe', 'db', 'sqlite', 'lck'];

    protected ServerFiles $files;

    protected ServerControl $control;

    protected ServerEnvironment $environment;

    protected ModrinthClient $modrinth;

    protected ModAnalyzer $analyzer;

    protected ChangeService $changes;

    /** @var string[]|null */
    protected ?array $allowed = null;

    /** @var array<string, mixed>|null */
    public ?array $finish = null;

    /** @var array<string, mixed>|null */
    public ?array $lastTestStart = null;

    public function __construct(protected DebugSession $session)
    {
        $server = $session->server;

        $this->files = new ServerFiles($server);
        $this->control = new ServerControl($server);
        $this->environment = new ServerEnvironment($server, $this->files);
        $this->modrinth = new ModrinthClient();
        $this->analyzer = new ModAnalyzer($this->files, $this->environment, $this->modrinth);
        $this->changes = new ChangeService($session, $this->files);
    }

    public function environment(): ServerEnvironment
    {
        return $this->environment;
    }

    public function control(): ServerControl
    {
        return $this->control;
    }

    protected function enabled(string $option, bool $default = true): bool
    {
        return (bool) $this->session->option($option, $default);
    }

    /**
     * Tool definitions for the Messages API. The list only depends on the session options,
     * so it stays identical for every request of a session (prompt cache friendly).
     *
     * @return array<int, array<string, mixed>>
     */
    public function definitions(): array
    {
        $tools = [];

        $tools[] = $this->tool('get_server_overview', 'Returns the server configuration (egg, docker image/java version, startup command, memory, variables), the detected mod loader + Minecraft version, the current power state and the files in the root folder. Call this first.', []);

        $tools[] = $this->tool('list_directory', 'Lists files and folders of a directory on the server (paths are relative to the server root, e.g. "mods" or "config/fml").', [
            'path' => ['type' => 'string', 'description' => 'Directory path, "" or "/" for the root folder.'],
        ], ['path']);

        $tools[] = $this->tool('read_file', 'Reads a text file (configs, logs, crash reports). Large files are cut: use tail_lines for logs. Binary files (jars, worlds) can not be read.', [
            'path' => ['type' => 'string'],
            'tail_lines' => ['type' => 'integer', 'description' => 'Only return the last N lines (good for logs).'],
            'head_lines' => ['type' => 'integer', 'description' => 'Only return the first N lines.'],
        ], ['path']);

        $tools[] = $this->tool('search_files', 'Searches file names (and small text files) inside a directory for a pattern.', [
            'pattern' => ['type' => 'string'],
            'directory' => ['type' => 'string', 'description' => 'Directory to search in, default is the root folder.'],
        ], ['pattern']);

        $tools[] = $this->tool('get_crash_reports', 'Returns the newest crash report (crash-reports/), JVM fatal error logs (hs_err_pid*.log) and the tail of logs/latest.log. Use after a crash.', [
            'log_lines' => ['type' => 'integer', 'description' => 'How many lines of logs/latest.log to return (default 250).'],
        ]);

        $tools[] = $this->tool('get_console_output', 'Returns the last lines of the live server console.', [
            'lines' => ['type' => 'integer', 'description' => 'Max 100.'],
        ]);

        $tools[] = $this->tool('analyze_mods', 'Analyzes every jar in a mods/plugins folder: reads mods.toml/neoforge.mods.toml/fabric.mod.json/plugin.yml, looks the file up on Modrinth by hash and returns per jar a verdict (ok, client_only, likely_client_only, broken, unknown), wrong loader, duplicate mod ids and missing required dependencies. Takes a while for big modpacks, results are cached for this session.', [
            'folder' => ['type' => 'string', 'description' => 'Default "mods". Use "plugins" for Paper/Spigot/Velocity.'],
            'only_problems' => ['type' => 'boolean', 'description' => 'Only list jars that are not "ok" (summary is always complete).'],
        ]);

        $tools[] = $this->tool('remove_files', 'Removes files or folders (e.g. client-only mods) by moving them into the quarantine folder /.ai-debugger/quarantine. Every removal can be undone by the user. Never delete world folders.', [
            'paths' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Paths relative to the server root.'],
            'reason' => ['type' => 'string', 'description' => 'Short reason shown to the user, e.g. "Iris is a client-only shader mod".'],
        ], ['paths', 'reason']);

        $tools[] = $this->tool('move_file', 'Moves or renames a file/folder (e.g. disabling a mod by renaming it to .disabled).', [
            'from' => ['type' => 'string'],
            'to' => ['type' => 'string'],
            'reason' => ['type' => 'string'],
        ], ['from', 'to', 'reason']);

        if ($this->enabled('edit_configs')) {
            $tools[] = $this->tool('write_file', 'Creates or overwrites a text file (configs, server.properties, ...). Always send the complete new content. The old content is stored and can be restored by the user.', [
                'path' => ['type' => 'string'],
                'content' => ['type' => 'string'],
                'reason' => ['type' => 'string'],
            ], ['path', 'content', 'reason']);
        }

        if (ModrinthClient::enabled()) {
            $tools[] = $this->tool('search_modrinth', 'Searches Modrinth for mods/plugins compatible with the server loader and Minecraft version.', [
                'query' => ['type' => 'string'],
                'project_type' => ['type' => 'string', 'enum' => ['mod', 'plugin', 'datapack']],
                'any_version' => ['type' => 'boolean', 'description' => 'Do not filter by loader/Minecraft version.'],
            ], ['query']);

            if ($this->enabled('update_mods')) {
                $tools[] = $this->tool('check_mod_updates', 'Checks Modrinth for newer versions of the jars in a folder that match the server loader and Minecraft version. Run analyze_mods first.', [
                    'folder' => ['type' => 'string', 'description' => 'Default "mods".'],
                ]);

                $tools[] = $this->tool('update_mod', 'Replaces a jar with another Modrinth version (default: newest version for the server loader + Minecraft version). The old jar is quarantined.', [
                    'path' => ['type' => 'string', 'description' => 'Path of the installed jar, e.g. "mods/create-1.21.1-6.0.4.jar".'],
                    'version_id' => ['type' => 'string', 'description' => 'Optional Modrinth version id to install instead of the newest one.'],
                    'reason' => ['type' => 'string'],
                ], ['path', 'reason']);
            }

            if ($this->enabled('install_missing_dependencies')) {
                $tools[] = $this->tool('install_mod', 'Downloads a mod/plugin from Modrinth into a folder, e.g. a missing required dependency. Picks the newest version for the server loader + Minecraft version unless version_id is given.', [
                    'project' => ['type' => 'string', 'description' => 'Modrinth project slug or id.'],
                    'version_id' => ['type' => 'string'],
                    'folder' => ['type' => 'string', 'description' => 'Default "mods".'],
                    'reason' => ['type' => 'string'],
                ], ['project', 'reason']);
            }
        }

        if ($this->enabled('allow_test_starts')) {
            $tools[] = $this->tool('test_start_server', 'Starts the server and watches the console until it finished starting ("Done (...)!"), crashed or the timeout is reached. Returns the result and the last console lines. Stops the server again afterwards unless stop_after is false.', [
                'timeout_seconds' => ['type' => 'integer', 'description' => 'Default 300. Big modpacks may need 600 or more.'],
                'stop_after' => ['type' => 'boolean', 'description' => 'Default true.'],
            ]);
        }

        $tools[] = $this->tool('power_action', 'Sends a power action to the server.', [
            'action' => ['type' => 'string', 'enum' => $this->enabled('allow_test_starts') ? ['start', 'stop', 'restart', 'kill'] : ['stop', 'kill']],
        ], ['action']);

        $tools[] = $this->tool('finish', 'Ends the session with a report for the user. Call this exactly once when everything is fixed or nothing more can be done.', [
            'summary' => ['type' => 'string', 'description' => 'Short summary in the language of the user (markdown allowed).'],
            'server_starts' => ['type' => 'boolean', 'description' => 'True if the last test start was successful.'],
            'fixed_issues' => ['type' => 'array', 'items' => ['type' => 'string']],
            'remaining_issues' => ['type' => 'array', 'items' => ['type' => 'string']],
            'recommendations' => ['type' => 'array', 'items' => ['type' => 'string']],
        ], ['summary', 'server_starts', 'fixed_issues', 'remaining_issues']);

        return $tools;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  string[]  $required
     * @return array<string, mixed>
     */
    protected function tool(string $name, string $description, array $properties, array $required = []): array
    {
        $schema = ['type' => 'object', 'properties' => (object) $properties];
        if (!empty($required)) {
            $schema['required'] = $required;
        }

        return [
            'name' => $name,
            'description' => $description,
            'inputSchema' => $schema,
        ];
    }

    /**
     * Executes a tool and returns the text result for the model.
     *
     * @param  array<string, mixed>  $input
     * @return array{content: string, is_error: bool}
     */
    public function execute(string $name, array $input): array
    {
        try {
            if (!$this->isAllowed($name)) {
                throw new InvalidArgumentException("Tool '$name' is not available in this session.");
            }

            $result = match ($name) {
                'get_server_overview' => $this->serverOverview(),
                'list_directory' => $this->listDirectory($input),
                'read_file' => $this->readFile($input),
                'search_files' => $this->searchFiles($input),
                'get_crash_reports' => $this->crashReports($input),
                'get_console_output' => ['lines' => $this->control->console(min(100, max(1, (int) ($input['lines'] ?? 100))))],
                'analyze_mods' => $this->analyzeMods($input),
                'remove_files' => $this->removeFiles($input),
                'move_file' => $this->moveFile($input),
                'write_file' => $this->writeFile($input),
                'search_modrinth' => $this->searchModrinth($input),
                'check_mod_updates' => $this->checkUpdates($input),
                'update_mod' => $this->updateMod($input),
                'install_mod' => $this->installMod($input),
                'test_start_server' => $this->testStart($input),
                'power_action' => $this->powerAction($input),
                'finish' => $this->finishSession($input),
                default => throw new InvalidArgumentException("Unknown tool '$name'"),
            };

            return ['content' => self::encode($result), 'is_error' => false];
        } catch (Throwable $exception) {
            if (!$exception instanceof InvalidArgumentException) {
                report($exception);
            }

            return ['content' => 'Error: ' . $exception->getMessage(), 'is_error' => true];
        }
    }

    protected function isAllowed(string $name): bool
    {
        $this->allowed ??= array_column($this->definitions(), 'name');

        return in_array($name, $this->allowed, true);
    }

    public static function encode(mixed $result): string
    {
        $json = is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);

        if (mb_strlen((string) $json) > self::MAX_RESULT) {
            $json = mb_substr((string) $json, 0, self::MAX_RESULT) . "\n... [truncated, result was too long]";
        }

        return (string) $json;
    }

    /** @param array<string, mixed> $input */
    protected function string(array $input, string $key, ?string $default = null): string
    {
        $value = $input[$key] ?? $default;
        if (!is_string($value) && !is_numeric($value)) {
            throw new InvalidArgumentException("Parameter '$key' is required.");
        }

        return (string) $value;
    }

    // ---------------------------------------------------------------- tools

    /** @return array<string, mixed> */
    protected function serverOverview(): array
    {
        $server = $this->session->server;
        $server->loadMissing(['egg', 'node']);

        $variables = [];
        foreach ($this->environment->variables() as $key => $value) {
            $variables[$key] = preg_match('/PASS|TOKEN|SECRET|KEY|AUTH/i', $key) ? '***' : $value;
        }

        $root = array_map(fn ($entry) => ($entry['directory'] ? $entry['name'] . '/' : $entry['name']) . ($entry['file'] ? ' (' . self::size($entry['size']) . ')' : ''), $this->files->listOrEmpty(''));

        return [
            'server' => [
                'name' => $server->name,
                'egg' => $server->egg->name ?? null,
                'docker_image' => $server->image,
                'startup' => $server->startup,
                'memory_mb' => $server->memory ?: 'unlimited',
                'disk_mb' => $server->disk ?: 'unlimited',
                'cpu_percent' => $server->cpu ?: 'unlimited',
                'variables' => $variables,
            ],
            'detected' => $this->environment->detect(),
            'state' => $this->control->state(),
            'root_files' => $root,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function listDirectory(array $input): array
    {
        $path = ServerFiles::normalize((string) ($input['path'] ?? ''));
        $entries = $this->files->list($path);

        return [
            'path' => '/' . $path,
            'count' => count($entries),
            'entries' => array_map(fn ($entry) => array_filter([
                'name' => $entry['name'] . ($entry['directory'] ? '/' : ''),
                'size' => $entry['file'] ? self::size($entry['size']) : null,
                'modified' => $entry['modified'] ? substr($entry['modified'], 0, 16) : null,
            ]), array_slice($entries, 0, 500)),
            'truncated' => count($entries) > 500,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function readFile(array $input): string
    {
        $path = ServerFiles::normalize($this->string($input, 'path'));
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, self::BINARY_EXTENSIONS, true)) {
            throw new InvalidArgumentException("/$path is a binary file. Use analyze_mods for jars.");
        }

        $stat = $this->files->stat($path) ?? throw new InvalidArgumentException("/$path does not exist.");
        if ($stat['directory']) {
            throw new InvalidArgumentException("/$path is a directory, use list_directory.");
        }

        $content = $this->readText($path, (int) $stat['size']);

        if (str_contains(substr($content, 0, 8000), "\0")) {
            throw new InvalidArgumentException("/$path looks like a binary file.");
        }

        $lines = preg_split('/\r?\n/', $content) ?: [];
        $total = count($lines);
        $note = null;

        if (!empty($input['tail_lines'])) {
            $lines = array_slice($lines, -max(1, (int) $input['tail_lines']));
            $note = 'last ' . count($lines) . " of $total lines";
        } elseif (!empty($input['head_lines'])) {
            $lines = array_slice($lines, 0, max(1, (int) $input['head_lines']));
            $note = 'first ' . count($lines) . " of $total lines";
        }

        $text = ServerControl::stripAnsi(implode("\n", $lines));

        // Logs: keep the end, that is where the error is
        if (mb_strlen($text) > self::MAX_RESULT - 200) {
            $text = "... [start cut, file is too long]\n" . mb_substr($text, -(self::MAX_RESULT - 300));
        }

        return "/$path (" . self::size((int) $stat['size']) . ($note ? ", $note" : '') . ")\n\n" . $text;
    }

    protected function readText(string $path, int $size): string
    {
        if ($size <= self::DIRECT_READ_LIMIT) {
            return $this->files->read($path);
        }

        // Too large for the content endpoint, download it and keep the last 1 MiB
        $local = storage_path('app/ai-debugger/' . Str::uuid() . '.txt');
        File::ensureDirectoryExists(dirname($local));

        try {
            $this->files->download($path, $local);

            $handle = fopen($local, 'rb');
            if (!$handle) {
                throw new Exception("Could not read /$path");
            }

            fseek($handle, max(0, $size - 1024 * 1024));
            $content = (string) stream_get_contents($handle);
            fclose($handle);

            return $content;
        } finally {
            @unlink($local);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function searchFiles(array $input): array
    {
        $pattern = $this->string($input, 'pattern');
        $directory = (string) ($input['directory'] ?? '');

        $results = $this->files->search($pattern, $directory);

        return [
            'results' => array_slice(array_map(fn ($entry) => is_array($entry) ? ($entry['name'] ?? $entry['attributes']['name'] ?? $entry) : $entry, $results), 0, 200),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function crashReports(array $input): array
    {
        $result = [];

        $reports = array_values(array_filter($this->files->listOrEmpty('crash-reports'), fn ($entry) => $entry['file']));
        usort($reports, fn ($a, $b) => strcmp((string) $b['modified'], (string) $a['modified']));

        $result['crash_reports'] = array_map(fn ($entry) => $entry['name'] . ' (' . ($entry['modified'] ? substr($entry['modified'], 0, 16) : '?') . ')', array_slice($reports, 0, 10));

        if (!empty($reports)) {
            $newest = ServerFiles::join('crash-reports', $reports[0]['name']);
            $content = $this->readText($newest, (int) $reports[0]['size']);
            $result['newest_crash_report'] = ['file' => '/' . $newest, 'content' => mb_substr($content, 0, 16_000)];
        }

        $jvm = array_values(array_filter($this->files->listOrEmpty(''), fn ($entry) => $entry['file'] && str_starts_with($entry['name'], 'hs_err_pid')));
        if (!empty($jvm)) {
            usort($jvm, fn ($a, $b) => strcmp((string) $b['modified'], (string) $a['modified']));
            $result['jvm_fatal_error'] = ['file' => '/' . $jvm[0]['name'], 'content' => mb_substr($this->readText($jvm[0]['name'], (int) $jvm[0]['size']), 0, 6000)];
        }

        $log = $this->files->stat('logs/latest.log');
        if ($log && $log['file']) {
            $lines = preg_split('/\r?\n/', $this->readText('logs/latest.log', (int) $log['size'])) ?: [];
            $count = min(1000, max(20, (int) ($input['log_lines'] ?? 250)));
            $result['latest_log'] = [
                'modified' => $log['modified'],
                'tail' => mb_substr(ServerControl::stripAnsi(implode("\n", array_slice($lines, -$count))), -16_000),
            ];
        }

        if (count($result) === 1 && empty($result['crash_reports'])) {
            $result['note'] = 'No crash reports or logs found. Use test_start_server or get_console_output.';
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function analyzeMods(array $input): array
    {
        $folder = (string) ($input['folder'] ?? 'mods');

        $report = $this->analyzer->analyze($folder, function (string $message) {
            $this->session->log(StepType::Info, $message);
        });

        if (!empty($input['only_problems'])) {
            $report['mods'] = array_values(array_filter($report['mods'], fn ($mod) => $mod['verdict'] !== 'ok' || !empty($mod['missing_dependencies']) || !empty($mod['reasons'])));
        }

        $summary = $report['summary'];
        $this->session->log(StepType::Info, trans('ai-debugger::strings.log.mods_analyzed', [
            'total' => $report['total_jars'],
            'client' => count($summary['client_only']),
            'likely' => count($summary['likely_client_only']),
            'missing' => count($summary['missing_dependencies']),
        ]), null, ['summary' => $summary]);

        return $report;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function removeFiles(array $input): array
    {
        $paths = $input['paths'] ?? null;
        if (!is_array($paths) || empty($paths)) {
            throw new InvalidArgumentException("Parameter 'paths' must be a non-empty list.");
        }

        $reason = (string) ($input['reason'] ?? '');
        $results = [];

        foreach (array_slice($paths, 0, 300) as $path) {
            try {
                $path = (string) $path;
                $this->guardWorld($path);
                $change = $this->changes->quarantine($path, $reason);
                $this->analyzer->forget($path);
                $results[] = ['path' => '/' . $change->path, 'result' => 'quarantined'];
            } catch (Throwable $exception) {
                $results[] = ['path' => $path, 'error' => $exception->getMessage()];
            }
        }

        return ['results' => $results];
    }

    /**
     * World folders are never removed by the agent, the risk of data loss is too high.
     */
    protected function guardWorld(string $path): void
    {
        $path = ServerFiles::normalize($path);
        $first = explode('/', $path)[0];

        $level = 'world';
        try {
            $properties = $this->files->read('server.properties', 64 * 1024);
            if (preg_match('/^level-name=(.+)$/m', $properties, $match)) {
                $level = trim($match[1]);
            }
        } catch (Throwable) {
            // no server.properties
        }

        if (in_array($first, [$level, $level . '_nether', $level . '_the_end', 'world', 'world_nether', 'world_the_end'], true) && !str_contains($path, '/serverconfig')) {
            if ($path === $first || str_starts_with($path, $first . '/region') || str_starts_with($path, $first . '/DIM') || str_starts_with($path, $first . '/playerdata')) {
                throw new InvalidArgumentException("/$path is world data and can not be removed by the AI.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function moveFile(array $input): array
    {
        $from = $this->string($input, 'from');
        $this->guardWorld($from);

        $change = $this->changes->move($from, $this->string($input, 'to'), (string) ($input['reason'] ?? ''));
        $this->analyzer->forget($from);

        return ['moved' => '/' . $change->path, 'to' => '/' . $change->target];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function writeFile(array $input): array
    {
        $path = ServerFiles::normalize($this->string($input, 'path'));
        $content = $input['content'] ?? null;
        if (!is_string($content)) {
            throw new InvalidArgumentException("Parameter 'content' is required.");
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, self::BINARY_EXTENSIONS, true)) {
            throw new InvalidArgumentException('Binary files can not be written.');
        }

        if (strtolower(ServerFiles::basename($path)) === 'eula.txt' && preg_match('/eula\s*=\s*true/i', $content) && !$this->enabled('accept_eula', false)) {
            throw new InvalidArgumentException('The Minecraft EULA must be accepted by the server owner. Tell the user to accept it (https://aka.ms/MinecraftEULA) instead.');
        }

        $change = $this->changes->write($path, $content, (string) ($input['reason'] ?? ''));

        return ['written' => '/' . $change->path, 'bytes' => strlen($content), 'created' => $change->previous_content === null];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function searchModrinth(array $input): array
    {
        $anyVersion = !empty($input['any_version']);
        $type = in_array($input['project_type'] ?? 'mod', ['mod', 'plugin', 'datapack'], true) ? (string) ($input['project_type'] ?? 'mod') : 'mod';

        $hits = $this->modrinth->search(
            $this->string($input, 'query'),
            $anyVersion ? [] : $this->environment->modrinthLoaders(),
            $anyVersion ? [] : $this->environment->modrinthGameVersions(),
            $type,
        );

        return [
            'hits' => array_map(fn ($hit) => [
                'slug' => $hit['slug'] ?? null,
                'project_id' => $hit['project_id'] ?? null,
                'title' => $hit['title'] ?? null,
                'description' => Str::limit((string) ($hit['description'] ?? ''), 160),
                'client_side' => $hit['client_side'] ?? null,
                'server_side' => $hit['server_side'] ?? null,
                'downloads' => $hit['downloads'] ?? null,
                'latest_game_versions' => array_slice($hit['versions'] ?? [], -4),
            ], $hits),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws Exception
     */
    protected function cachedMods(string $folder): array
    {
        $folder = ServerFiles::normalize($folder);
        $mods = [];

        foreach ($this->files->list($folder) as $entry) {
            if (!$entry['file'] || !preg_match('/\.jar$/i', $entry['name'])) {
                continue;
            }

            $path = ServerFiles::join($folder, $entry['name']);
            $mod = $this->analyzer->cached($path);

            if (!$mod) {
                $this->analyzer->analyze($folder);
                $mod = $this->analyzer->cached($path);
            }

            if ($mod) {
                $mods[$path] = $mod;
            }
        }

        return $mods;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function checkUpdates(array $input): array
    {
        $folder = (string) ($input['folder'] ?? 'mods');
        $mods = $this->cachedMods($folder);

        $hashes = array_values(array_filter(array_map(fn ($mod) => $mod['sha1'] ?? null, $mods)));
        $latest = $this->modrinth->latestVersionsByHash($hashes, $this->environment->modrinthLoaders(), $this->environment->modrinthGameVersions());

        $updates = [];
        $unknown = [];

        foreach ($mods as $path => $mod) {
            $sha1 = $mod['sha1'] ?? null;
            $version = $sha1 ? ($latest[$sha1] ?? null) : null;

            if (!$version) {
                if (!isset($mod['modrinth'])) {
                    $unknown[] = $mod['file'];
                }

                continue;
            }

            if (($version['id'] ?? null) === ($mod['modrinth']['version_id'] ?? null)) {
                continue;
            }

            $file = ModrinthClient::primaryFile($version);
            if (!$file || $file['sha1'] === $sha1) {
                continue;
            }

            $updates[] = [
                'path' => '/' . $path,
                'installed' => $mod['modrinth']['version'] ?? ($mod['mods'][0]['version'] ?? null),
                'newest' => $version['version_number'] ?? null,
                'version_id' => $version['id'] ?? null,
                'file' => $file['filename'],
                'version_type' => $version['version_type'] ?? null,
                'published' => isset($version['date_published']) ? substr((string) $version['date_published'], 0, 10) : null,
            ];
        }

        return [
            'loader' => $this->environment->modrinthLoaders(),
            'minecraft' => $this->environment->modrinthGameVersions(),
            'updates' => $updates,
            'not_on_modrinth' => $unknown,
            'note' => 'Only update mods that are related to the problem or are known to be broken/outdated. Updating everything at once makes errors harder to find.',
        ];
    }

    /**
     * @param  array<string, mixed>  $version
     */
    protected function assertCompatible(array $version): void
    {
        $loaders = $this->environment->modrinthLoaders();
        $gameVersions = $this->environment->modrinthGameVersions();

        if (!empty($loaders) && empty(array_intersect($loaders, $version['loaders'] ?? []))) {
            throw new InvalidArgumentException('Version ' . ($version['version_number'] ?? '?') . ' is for ' . implode('/', $version['loaders'] ?? []) . ', the server runs ' . implode('/', $loaders) . '.');
        }

        if (!empty($gameVersions) && empty(array_intersect($gameVersions, $version['game_versions'] ?? []))) {
            throw new InvalidArgumentException('Version ' . ($version['version_number'] ?? '?') . ' does not support Minecraft ' . implode('/', $gameVersions) . '.');
        }
    }

    /**
     * @param  array{url: string, filename: string, sha1: ?string}  $file
     */
    protected function assertModrinthUrl(array $file): void
    {
        $host = parse_url($file['url'], PHP_URL_HOST);

        if (!is_string($host) || !in_array($host, ['cdn.modrinth.com', 'cdn-raw.modrinth.com'], true)) {
            throw new InvalidArgumentException('Only downloads from cdn.modrinth.com are allowed.');
        }

        if (!preg_match('/\.(jar|zip)$/i', $file['filename'])) {
            throw new InvalidArgumentException('Only .jar/.zip files can be installed.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function updateMod(array $input): array
    {
        $path = ServerFiles::assertWritable($this->string($input, 'path'));
        $versionId = isset($input['version_id']) && $input['version_id'] !== '' ? (string) $input['version_id'] : null;

        $mod = $this->analyzer->cached($path);
        if (!$mod) {
            $this->cachedMods(ServerFiles::dirname($path));
            $mod = $this->analyzer->cached($path);
        }

        if ($versionId) {
            $version = $this->modrinth->version($versionId) ?? throw new InvalidArgumentException("Modrinth version $versionId not found.");
        } else {
            $sha1 = $mod['sha1'] ?? throw new InvalidArgumentException("/$path is not known on Modrinth, pass a version_id or use install_mod.");
            $latest = $this->modrinth->latestVersionsByHash([$sha1], $this->environment->modrinthLoaders(), $this->environment->modrinthGameVersions());
            $version = $latest[$sha1] ?? throw new InvalidArgumentException("No compatible version of /$path found on Modrinth.");
        }

        $this->assertCompatible($version);

        $file = ModrinthClient::primaryFile($version) ?? throw new InvalidArgumentException('The version has no downloadable file.');
        $this->assertModrinthUrl($file);

        if ($mod && $file['sha1'] && $file['sha1'] === ($mod['sha1'] ?? null)) {
            return ['result' => 'already installed', 'version' => $version['version_number'] ?? null];
        }

        $change = $this->changes->replaceWithDownload($path, $file['url'], $file['filename'], (string) ($input['reason'] ?? ''), [
            'from_version' => $mod['modrinth']['version'] ?? ($mod['mods'][0]['version'] ?? null),
            'to_version' => $version['version_number'] ?? null,
            'version_id' => $version['id'] ?? null,
        ]);

        $this->analyzer->forget($path);

        return [
            'result' => 'updated',
            'old' => '/' . $change->path,
            'new' => '/' . ($change->meta['new_path'] ?? ''),
            'version' => $version['version_number'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function installMod(array $input): array
    {
        $projectId = $this->string($input, 'project');
        $folder = (string) ($input['folder'] ?? 'mods');

        $project = $this->modrinth->project($projectId) ?? throw new InvalidArgumentException("Modrinth project '$projectId' not found.");

        if (($project['server_side'] ?? null) === 'unsupported') {
            throw new InvalidArgumentException(($project['title'] ?? $projectId) . ' is a client-only project and must not be installed on a server.');
        }

        if (!empty($input['version_id'])) {
            $version = $this->modrinth->version((string) $input['version_id']) ?? throw new InvalidArgumentException('Version not found.');
        } else {
            $versions = $this->modrinth->projectVersions($projectId, $this->environment->modrinthLoaders(), $this->environment->modrinthGameVersions());
            // Prefer releases over betas/alphas
            $version = collect($versions)->firstWhere('version_type', 'release') ?? ($versions[0] ?? null);
            $version ?? throw new InvalidArgumentException('No version of ' . ($project['title'] ?? $projectId) . ' for ' . implode('/', $this->environment->modrinthLoaders()) . ' ' . implode('/', $this->environment->modrinthGameVersions()) . ' found.');
        }

        $this->assertCompatible($version);

        $file = ModrinthClient::primaryFile($version) ?? throw new InvalidArgumentException('The version has no downloadable file.');
        $this->assertModrinthUrl($file);

        // Same project installed already (other file name)?
        foreach ($this->files->listOrEmpty($folder) as $entry) {
            $cached = $this->analyzer->cached(ServerFiles::join($folder, $entry['name']));
            if ($cached && ($cached['modrinth']['project_id'] ?? null) === ($project['id'] ?? false)) {
                throw new InvalidArgumentException(($project['title'] ?? $projectId) . " is already installed as /$folder/{$entry['name']}. Use update_mod instead.");
            }
        }

        $change = $this->changes->install($folder, $file['url'], $file['filename'], (string) ($input['reason'] ?? ''), [
            'project' => $project['slug'] ?? $projectId,
            'version' => $version['version_number'] ?? null,
            'version_id' => $version['id'] ?? null,
        ]);

        $required = array_values(array_filter(array_map(
            fn ($dependency) => ($dependency['dependency_type'] ?? '') === 'required' ? ($dependency['project_id'] ?? null) : null,
            $version['dependencies'] ?? [],
        )));

        return [
            'result' => 'installed',
            'path' => '/' . $change->path,
            'version' => $version['version_number'] ?? null,
            'required_dependencies' => $required ?: null,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function testStart(array $input): array
    {
        $max = max(60, (int) config('ai-debugger.max_test_start_seconds', 900));
        $timeout = min($max, max(60, (int) ($input['timeout_seconds'] ?? 300)));
        $stopAfter = !array_key_exists('stop_after', $input) || (bool) $input['stop_after'];

        $this->session->log(StepType::Info, trans('ai-debugger::strings.log.test_start', ['seconds' => $timeout]));

        $lastLog = 0;
        $result = $this->control->testStart($timeout, function (string $state) use (&$lastLog) {
            if ($this->session->isCancelRequested()) {
                throw new Exception(trans('ai-debugger::strings.log.cancelled'));
            }

            if (time() - $lastLog >= 30) {
                $lastLog = time();
                $this->session->steps()->latest('id')->first()?->update(['content' => 'state: ' . $state]);
            }
        });

        $this->lastTestStart = $result;

        $type = $result['result'] === 'started' ? StepType::Success : StepType::Warning;
        $this->session->log($type, trans('ai-debugger::strings.log.test_result.' . $result['result'], ['seconds' => $result['seconds']]), $result['matched_line']);

        if ($result['result'] === 'started' && $stopAfter) {
            $this->control->stopAndWait();
        }

        if ($result['result'] === 'timeout') {
            // Leave the server in a defined state
            $this->control->stopAndWait();
        }

        return [
            'result' => $result['result'],
            'seconds' => $result['seconds'],
            'state' => $result['state'],
            'matched_line' => $result['matched_line'],
            'console' => array_slice($result['console'], -80),
            'stopped_again' => $result['result'] !== 'started' || $stopAfter,
            'hint' => $result['result'] === 'started' ? null : 'Use get_crash_reports for the full crash report and latest.log.',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function powerAction(array $input): array
    {
        $action = $this->string($input, 'action');

        if (!in_array($action, ['start', 'stop', 'restart', 'kill'], true)) {
            throw new InvalidArgumentException('Unknown power action.');
        }

        if (in_array($action, ['start', 'restart'], true) && !$this->enabled('allow_test_starts')) {
            throw new InvalidArgumentException('Starting the server is disabled for this session.');
        }

        if ($action === 'stop') {
            $this->control->stopAndWait();
        } else {
            $this->control->power($action);
        }

        return ['sent' => $action, 'state' => $this->control->state()];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function finishSession(array $input): array
    {
        $list = fn (string $key) => array_values(array_filter(array_map(fn ($item) => is_scalar($item) ? trim((string) $item) : null, (array) ($input[$key] ?? []))));

        $this->finish = [
            'summary' => $this->string($input, 'summary'),
            'server_starts' => (bool) ($input['server_starts'] ?? false),
            'fixed_issues' => $list('fixed_issues'),
            'remaining_issues' => $list('remaining_issues'),
            'recommendations' => $list('recommendations'),
        ];

        return ['result' => 'Session finished. Thank you.'];
    }

    public static function size(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KiB';
        }

        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / 1024 / 1024, 1) . ' MiB';
        }

        return round($bytes / 1024 / 1024 / 1024, 2) . ' GiB';
    }
}
