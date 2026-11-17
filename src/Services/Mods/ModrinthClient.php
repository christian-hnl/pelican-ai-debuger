<?php

namespace ChristianHnl\AiDebugger\Services\Mods;

use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Small Modrinth API v2 client.
 */
class ModrinthClient
{
    protected const BASE_URL = 'https://api.modrinth.com/v2';

    protected function http(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->acceptJson()
            ->withUserAgent('christian-hnl/pelican-ai-debugger/1.0.0 (github.com/christian-hnl/pelican-ai-debuger)')
            ->timeout(30)
            ->connectTimeout(10)
            ->retry(2, 1000, throw: false);
    }

    public static function enabled(): bool
    {
        return (bool) config('ai-debugger.modrinth', true);
    }

    /**
     * Finds the Modrinth versions for the given sha1 hashes.
     *
     * @param  string[]  $hashes
     * @return array<string, array<string, mixed>> hash => version
     */
    public function versionsByHash(array $hashes): array
    {
        $result = [];

        foreach (array_chunk(array_values(array_unique($hashes)), 200) as $chunk) {
            $response = $this->http()->post('/version_files', ['hashes' => $chunk, 'algorithm' => 'sha1']);

            if ($response->successful() && is_array($response->json())) {
                $result += $response->json();
            }
        }

        return $result;
    }

    /**
     * Finds the newest compatible version for each hash.
     *
     * @param  string[]  $hashes
     * @param  string[]  $loaders
     * @param  string[]  $gameVersions
     * @return array<string, array<string, mixed>> hash => latest version
     */
    public function latestVersionsByHash(array $hashes, array $loaders, array $gameVersions): array
    {
        $result = [];

        foreach (array_chunk(array_values(array_unique($hashes)), 200) as $chunk) {
            $payload = ['hashes' => $chunk, 'algorithm' => 'sha1', 'loaders' => array_values($loaders)];
            if (!empty($gameVersions)) {
                $payload['game_versions'] = array_values($gameVersions);
            }

            $response = $this->http()->post('/version_files/update', $payload);

            if ($response->successful() && is_array($response->json())) {
                $result += $response->json();
            }
        }

        return $result;
    }

    /**
     * @param  string[]  $ids
     * @return array<string, array<string, mixed>> project id => project
     */
    public function projects(array $ids): array
    {
        $result = [];

        foreach (array_chunk(array_values(array_unique($ids)), 100) as $chunk) {
            $response = $this->http()->get('/projects', ['ids' => json_encode($chunk)]);

            if ($response->successful()) {
                foreach ((array) $response->json() as $project) {
                    if (is_array($project) && isset($project['id'])) {
                        $result[$project['id']] = $project;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function project(string $idOrSlug): ?array
    {
        $response = $this->http()->get('/project/' . rawurlencode($idOrSlug));

        return $response->successful() ? $response->json() : null;
    }

    /**
     * @param  string[]  $loaders
     * @param  string[]  $gameVersions
     * @return array<int, array<string, mixed>>
     */
    public function projectVersions(string $idOrSlug, array $loaders = [], array $gameVersions = []): array
    {
        $query = [];
        if (!empty($loaders)) {
            $query['loaders'] = json_encode(array_values($loaders));
        }
        if (!empty($gameVersions)) {
            $query['game_versions'] = json_encode(array_values($gameVersions));
        }

        $response = $this->http()->get('/project/' . rawurlencode($idOrSlug) . '/version', $query);

        if (!$response->successful() || !is_array($response->json())) {
            return [];
        }

        $versions = $response->json();
        usort($versions, fn ($a, $b) => strcmp($b['date_published'] ?? '', $a['date_published'] ?? ''));

        return $versions;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function version(string $id): ?array
    {
        $response = $this->http()->get('/version/' . rawurlencode($id));

        return $response->successful() ? $response->json() : null;
    }

    /**
     * @param  string[]  $loaders
     * @param  string[]  $gameVersions
     * @return array<int, array<string, mixed>>
     *
     * @throws Exception
     */
    public function search(string $query, array $loaders = [], array $gameVersions = [], string $projectType = 'mod', int $limit = 10): array
    {
        $facets = [['project_type:' . $projectType]];
        if (!empty($loaders)) {
            $facets[] = array_map(fn ($loader) => 'categories:' . $loader, $loaders);
        }
        if (!empty($gameVersions)) {
            $facets[] = array_map(fn ($version) => 'versions:' . $version, $gameVersions);
        }

        $response = $this->http()->get('/search', [
            'query' => $query,
            'facets' => json_encode($facets),
            'limit' => $limit,
        ]);

        if (!$response->successful()) {
            throw new Exception('Modrinth search failed (HTTP ' . $response->status() . ')');
        }

        return (array) ($response->json('hits') ?? []);
    }

    /**
     * Picks the primary file of a version.
     *
     * @param  array<string, mixed>  $version
     * @return array{url: string, filename: string, sha1: ?string}|null
     */
    public static function primaryFile(array $version): ?array
    {
        $files = $version['files'] ?? [];
        if (!is_array($files) || empty($files)) {
            return null;
        }

        $file = collect($files)->firstWhere('primary', true) ?? $files[0];

        if (!isset($file['url'], $file['filename'])) {
            return null;
        }

        return ['url' => (string) $file['url'], 'filename' => (string) $file['filename'], 'sha1' => $file['hashes']['sha1'] ?? null];
    }
}
