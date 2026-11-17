<?php

namespace ChristianHnl\AiDebugger\Services\Mods;

use ChristianHnl\AiDebugger\Services\ServerEnvironment;
use ChristianHnl\AiDebugger\Services\ServerFiles;
use Exception;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Downloads every jar of a folder, reads its metadata, looks it up on Modrinth and
 * checks for client-only mods, wrong loaders, duplicates and missing dependencies.
 */
class ModAnalyzer
{
    /** @var array<string, array<string, mixed>> cache: "folder/file:size" => analysis */
    protected array $cache = [];

    public function __construct(
        protected ServerFiles $files,
        protected ServerEnvironment $environment,
        protected ModrinthClient $modrinth,
    ) {}

    /**
     * @param  callable(string): void|null  $progress
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function analyze(string $folder, ?callable $progress = null): array
    {
        $folder = ServerFiles::normalize($folder);
        $entries = $this->files->list($folder);

        $jars = array_values(array_filter($entries, fn ($entry) => $entry['file'] && preg_match('/\.(jar|zip)$/i', $entry['name'])));
        $disabled = array_values(array_filter($entries, fn ($entry) => $entry['file'] && preg_match('/\.(disabled|jar\.old|bak)$/i', $entry['name'])));

        $maxSize = max(1, (int) config('ai-debugger.max_jar_size', 128)) * 1024 * 1024;

        $tmpDir = storage_path('app/ai-debugger/' . uniqid('mods-', true));
        File::ensureDirectoryExists($tmpDir);

        $mods = [];

        try {
            foreach ($jars as $index => $jar) {
                $path = ServerFiles::join($folder, $jar['name']);
                $key = $path . ':' . $jar['size'];

                if ($progress && $index % 10 === 0) {
                    $progress(sprintf('Reading %s (%d/%d)', $jar['name'], $index + 1, count($jars)));
                }

                if (isset($this->cache[$key])) {
                    $mods[] = $this->cache[$key];

                    continue;
                }

                $mod = [
                    '_key' => $key,
                    'file' => $jar['name'],
                    'size_kb' => (int) round($jar['size'] / 1024),
                    'sha1' => null,
                ];

                if ($jar['size'] > $maxSize) {
                    $mod['error'] = 'File too large to analyze';
                    $mods[] = $mod;

                    continue;
                }

                if ($jar['size'] === 0) {
                    $mod['error'] = 'Empty file (0 bytes) - broken download';
                    $mods[] = $mod;

                    continue;
                }

                $local = $tmpDir . '/' . md5($jar['name']) . '.jar';

                try {
                    $this->files->download($path, $local);
                    $mod['sha1'] = sha1_file($local) ?: null;
                    $mod += JarInspector::inspect($local);
                } catch (Throwable $exception) {
                    $mod['error'] = 'Could not download/read: ' . $exception->getMessage();
                } finally {
                    @unlink($local);
                }

                $this->cache[$key] = $mod;
                $mods[] = $mod;
            }
        } finally {
            File::deleteDirectory($tmpDir);
        }

        if ($progress) {
            $progress('Looking up mods on Modrinth');
        }

        $mods = $this->enrichWithModrinth($mods);

        foreach ($mods as $mod) {
            $this->cache[$mod['_key']] = $mod;
        }

        return $this->buildReport($folder, $mods, $disabled);
    }

    /**
     * @param  array<int, array<string, mixed>>  $mods
     * @return array<int, array<string, mixed>>
     */
    protected function enrichWithModrinth(array $mods): array
    {
        if (!ModrinthClient::enabled()) {
            return $mods;
        }

        $hashes = array_values(array_filter(array_column($mods, 'sha1')));
        if (empty($hashes)) {
            return $mods;
        }

        try {
            $versions = $this->modrinth->versionsByHash($hashes);
            $projects = $this->modrinth->projects(array_values(array_filter(array_map(fn ($version) => $version['project_id'] ?? null, $versions))));
        } catch (Throwable $exception) {
            report($exception);

            return $mods;
        }

        foreach ($mods as &$mod) {
            $version = $mod['sha1'] ? ($versions[$mod['sha1']] ?? null) : null;
            if (!$version) {
                continue;
            }

            $project = $projects[$version['project_id']] ?? [];

            $mod['modrinth'] = [
                'project_id' => $version['project_id'],
                'version_id' => $version['id'] ?? null,
                'slug' => $project['slug'] ?? null,
                'title' => $project['title'] ?? null,
                'version' => $version['version_number'] ?? null,
                'loaders' => $version['loaders'] ?? [],
                'game_versions' => array_slice($version['game_versions'] ?? [], -6),
                'client_side' => $project['client_side'] ?? null,
                'server_side' => $project['server_side'] ?? null,
                'required_dependencies' => array_values(array_map(
                    fn ($dependency) => $dependency['project_id'] ?? $dependency['version_id'] ?? null,
                    array_filter($version['dependencies'] ?? [], fn ($dependency) => ($dependency['dependency_type'] ?? '') === 'required')
                )),
            ];
        }

        return $mods;
    }

    /**
     * @param  array<int, array<string, mixed>>  $mods
     * @param  array<int, array<string, mixed>>  $disabled
     * @return array<string, mixed>
     */
    protected function buildReport(string $folder, array $mods, array $disabled): array
    {
        $environment = $this->environment->detect();
        $serverLoader = $environment['loader'];

        // Which mod ids exist in this folder (and from which file)
        $provided = [];
        foreach ($mods as $mod) {
            foreach ($mod['mods'] ?? [] as $entry) {
                $provided[strtolower($entry['id'])][] = $mod['file'];
            }
        }

        $installedProjects = array_filter(array_map(fn ($mod) => $mod['modrinth']['project_id'] ?? null, $mods));

        $result = [];
        $flags = [
            'client_only' => [],
            'likely_client_only' => [],
            'wrong_loader' => [],
            'broken' => [],
            'duplicates' => [],
            'missing_dependencies' => [],
        ];

        foreach ($mods as $mod) {
            $ids = array_map(fn ($entry) => $entry['id'], $mod['mods'] ?? []);
            $hints = $mod['client_side_hints'] ?? [];

            $verdict = 'ok';
            $reasons = [];

            if (!empty($mod['error'])) {
                $verdict = 'unknown';
                $reasons[] = $mod['error'];
                if (str_contains($mod['error'], 'broken') || str_contains($mod['error'], 'Not a valid')) {
                    $flags['broken'][] = $mod['file'];
                    $verdict = 'broken';
                }
            }

            $serverSide = $mod['modrinth']['server_side'] ?? null;
            if ($serverSide === 'unsupported') {
                $verdict = 'client_only';
                $reasons[] = 'Modrinth: server_side=unsupported';
            } elseif (($mod['environment'] ?? null) === 'client') {
                $verdict = 'client_only';
                $reasons[] = 'declares environment=client';
            } else {
                $known = null;
                foreach ($ids as $id) {
                    $known ??= KnownMods::clientOnlyReason($id);
                }
                $known ??= empty($ids) ? KnownMods::clientFilenameHint($mod['file']) : null;

                if ($known && $serverSide !== 'required') {
                    $verdict = $serverSide === 'optional' ? 'likely_client_only' : 'client_only';
                    $reasons[] = 'known client mod: ' . $known;
                } elseif (!empty($hints) && $serverSide !== 'required') {
                    $verdict = 'likely_client_only';
                }
            }

            if ($verdict === 'client_only') {
                $flags['client_only'][] = $mod['file'];
            } elseif ($verdict === 'likely_client_only') {
                $flags['likely_client_only'][] = $mod['file'];
            }

            // Loader mismatch (e.g. a Fabric mod on a NeoForge server)
            $loaders = $mod['loaders'] ?? [];
            if ($serverLoader && !empty($loaders) && !$this->loaderCompatible($serverLoader, $loaders, $mod['modrinth']['loaders'] ?? [])) {
                $flags['wrong_loader'][] = $mod['file'];
                $reasons[] = 'built for ' . implode('/', $loaders) . ' but the server runs ' . $serverLoader;
            }

            // Missing required dependencies
            $missing = [];
            foreach ($mod['dependencies'] ?? [] as $dependency) {
                $id = strtolower($dependency['id']);
                if (!$dependency['required'] || in_array($id, JarInspector::PLATFORM_IDS, true) || isset($provided[$id])) {
                    continue;
                }

                // Client side only dependencies are not needed on a server
                if (($dependency['side'] ?? null) === 'CLIENT') {
                    continue;
                }

                // Fabric api is provided as "fabric-api" but mods depend on "fabric" or its modules
                if (str_starts_with($id, 'fabric') && (isset($provided['fabric-api']) || isset($provided['fabric']))) {
                    continue;
                }

                $missing[] = $dependency['id'] . ($dependency['version'] ? ' ' . $dependency['version'] : '');
            }

            foreach ($mod['modrinth']['required_dependencies'] ?? [] as $projectId) {
                if ($projectId && !in_array($projectId, $installedProjects, true)) {
                    $missing[] = "modrinth project $projectId";
                }
            }

            if (!empty($missing)) {
                $flags['missing_dependencies'][$mod['file']] = array_values(array_unique($missing));
            }

            $result[] = array_filter([
                'file' => $mod['file'],
                'verdict' => $verdict,
                'ids' => $ids ?: null,
                'name' => $mod['modrinth']['title'] ?? ($mod['mods'][0]['name'] ?? null),
                'version' => $mod['mods'][0]['version'] ?? ($mod['modrinth']['version'] ?? null),
                'loaders' => $loaders ?: null,
                'minecraft' => $mod['minecraft'] ?? null,
                'modrinth' => isset($mod['modrinth']) ? array_filter([
                    'slug' => $mod['modrinth']['slug'],
                    'client' => $mod['modrinth']['client_side'],
                    'server' => $mod['modrinth']['server_side'],
                    'game_versions' => $mod['modrinth']['game_versions'],
                ]) : null,
                'hints' => $hints ?: null,
                'reasons' => $reasons ?: null,
                'missing_dependencies' => $flags['missing_dependencies'][$mod['file']] ?? null,
            ], fn ($value) => $value !== null);
        }

        foreach ($provided as $id => $files) {
            if (count($files) > 1) {
                $flags['duplicates'][$id] = $files;
            }
        }

        return [
            'folder' => '/' . $folder,
            'server' => $environment,
            'total_jars' => count($mods),
            'disabled_files' => array_column($disabled, 'name'),
            'summary' => [
                'client_only' => $flags['client_only'],
                'likely_client_only' => $flags['likely_client_only'],
                'wrong_loader' => $flags['wrong_loader'],
                'broken' => $flags['broken'],
                'duplicate_mod_ids' => $flags['duplicates'],
                'missing_dependencies' => $flags['missing_dependencies'],
            ],
            'mods' => $result,
        ];
    }

    /**
     * Returns the cached analysis entry for a file (sha1 etc.) if this folder was analyzed already.
     *
     * @return array<string, mixed>|null
     */
    public function cached(string $path): ?array
    {
        $path = ServerFiles::normalize($path);

        foreach ($this->cache as $key => $mod) {
            if (str_starts_with($key, $path . ':')) {
                return $mod;
            }
        }

        return null;
    }

    public function forget(string $path): void
    {
        $path = ServerFiles::normalize($path);

        foreach (array_keys($this->cache) as $key) {
            if (str_starts_with($key, $path . ':')) {
                unset($this->cache[$key]);
            }
        }
    }

    /**
     * @param  string[]  $jarLoaders
     * @param  string[]  $modrinthLoaders
     */
    protected function loaderCompatible(string $serverLoader, array $jarLoaders, array $modrinthLoaders): bool
    {
        $all = array_unique(array_merge($jarLoaders, $modrinthLoaders));

        $compatible = match ($serverLoader) {
            // NeoForge for 1.20.1 still loads Forge mods, later versions don't
            'neoforge' => str_starts_with((string) $this->environment->detect()['minecraft'], '1.20.1') ? ['neoforge', 'forge'] : ['neoforge'],
            'forge' => ['forge', 'forge-legacy'],
            'fabric' => ['fabric', 'quilt'],
            'quilt' => ['quilt', 'fabric'],
            'paper', 'purpur', 'spigot' => ['paper', 'bukkit', 'spigot', 'purpur', 'folia'],
            'velocity' => ['velocity'],
            'bungeecord' => ['bungeecord', 'waterfall'],
            default => $all,
        };

        return !empty(array_intersect($all, $compatible));
    }
}
