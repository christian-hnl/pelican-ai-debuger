<?php

namespace ChristianHnl\AiDebugger\Services\Mods;

use Throwable;
use ZipArchive;

/**
 * Reads the metadata of a mod/plugin jar (NeoForge, Forge, Fabric, Quilt, legacy Forge, Bukkit/Paper, Velocity, BungeeCord).
 */
class JarInspector
{
    // Dependencies that are part of the platform and never a separate jar
    public const PLATFORM_IDS = ['minecraft', 'forge', 'neoforge', 'fabricloader', 'fabric-loader', 'quilt_loader', 'java', 'javafml', 'lowcodefml', 'mcp', 'quilt_base'];

    /**
     * @return array{
     *     loaders: string[],
     *     mods: array<int, array{id: string, name: ?string, version: ?string}>,
     *     environment: ?string,
     *     client_side_hints: string[],
     *     dependencies: array<int, array{id: string, required: bool, version: ?string, side: ?string, owner: ?string}>,
     *     minecraft: ?string,
     *     loader_version: ?string,
     *     error: ?string
     * }
     */
    public static function inspect(string $file): array
    {
        $info = [
            'loaders' => [],
            'mods' => [],
            'environment' => null,
            'client_side_hints' => [],
            'dependencies' => [],
            'minecraft' => null,
            'loader_version' => null,
            'error' => null,
        ];

        $zip = new ZipArchive();
        $opened = $zip->open($file, ZipArchive::RDONLY);
        if ($opened !== true) {
            $info['error'] = 'Not a valid jar/zip file (corrupt or incomplete download?)';

            return $info;
        }

        try {
            $manifestVersion = self::manifestValue($zip, 'Implementation-Version');

            if (($toml = $zip->getFromName('META-INF/neoforge.mods.toml')) !== false) {
                $info['loaders'][] = 'neoforge';
                self::readModsToml($info, TomlLite::parse($toml), $manifestVersion);
            }

            if (($toml = $zip->getFromName('META-INF/mods.toml')) !== false) {
                $info['loaders'][] = 'forge';
                self::readModsToml($info, TomlLite::parse($toml), $manifestVersion);
            }

            if (($json = $zip->getFromName('fabric.mod.json')) !== false) {
                $info['loaders'][] = 'fabric';
                self::readFabric($info, self::json($json));
            }

            if (($json = $zip->getFromName('quilt.mod.json')) !== false) {
                $info['loaders'][] = 'quilt';
                self::readQuilt($info, self::json($json));
            }

            if (empty($info['loaders']) && ($json = $zip->getFromName('mcmod.info')) !== false) {
                $info['loaders'][] = 'forge-legacy';
                $data = self::json($json);
                $list = $data['modList'] ?? $data;
                foreach (is_array($list) ? $list : [] as $mod) {
                    if (is_array($mod) && isset($mod['modid'])) {
                        $info['mods'][] = ['id' => (string) $mod['modid'], 'name' => $mod['name'] ?? null, 'version' => $mod['version'] ?? null];
                        $info['minecraft'] ??= $mod['mcversion'] ?? null;
                    }
                }
            }

            foreach (['paper-plugin.yml' => 'paper', 'plugin.yml' => 'bukkit', 'bungee.yml' => 'bungeecord'] as $name => $loader) {
                if (($yml = $zip->getFromName($name)) !== false) {
                    $info['loaders'][] = $loader;
                    $id = self::yamlValue($yml, 'name');
                    if ($id) {
                        $info['mods'][] = ['id' => $id, 'name' => $id, 'version' => self::yamlValue($yml, 'version')];
                    }
                    foreach (['depend', 'dependencies'] as $key) {
                        foreach (self::yamlList($yml, $key) as $dependency) {
                            $info['dependencies'][] = ['id' => $dependency, 'required' => true, 'version' => null, 'side' => null, 'owner' => $id];
                        }
                    }
                    $info['minecraft'] ??= self::yamlValue($yml, 'api-version');

                    break;
                }
            }

            if (($json = $zip->getFromName('velocity-plugin.json')) !== false) {
                $info['loaders'][] = 'velocity';
                $data = self::json($json);
                if (isset($data['id'])) {
                    $info['mods'][] = ['id' => (string) $data['id'], 'name' => $data['name'] ?? null, 'version' => $data['version'] ?? null];
                }
            }

            if (empty($info['loaders'])) {
                $info['error'] = 'No mod metadata found (library, resource pack or not a mod?)';
            }
        } catch (Throwable $exception) {
            $info['error'] = 'Could not read metadata: ' . $exception->getMessage();
        } finally {
            $zip->close();
        }

        $info['loaders'] = array_values(array_unique($info['loaders']));

        return $info;
    }

    /**
     * @param  array<string, mixed>  $info
     * @param  array<string, mixed>  $toml
     */
    protected static function readModsToml(array &$info, array $toml, ?string $manifestVersion): void
    {
        $info['loader_version'] ??= isset($toml['loaderVersion']) ? (string) $toml['loaderVersion'] : null;

        if (($toml['clientSideOnly'] ?? false) === true) {
            $info['environment'] = 'client';
            $info['client_side_hints'][] = 'clientSideOnly=true in mods.toml';
        }

        $modIds = [];
        foreach (($toml['mods'] ?? []) as $mod) {
            if (!is_array($mod) || !isset($mod['modId'])) {
                continue;
            }

            $version = isset($mod['version']) ? (string) $mod['version'] : null;
            if ($version === '${file.jarVersion}') {
                $version = $manifestVersion;
            }

            $modIds[] = (string) $mod['modId'];
            $info['mods'][] = ['id' => (string) $mod['modId'], 'name' => $mod['displayName'] ?? null, 'version' => $version];

            if (($mod['displayTest'] ?? null) === 'IGNORE_ALL_VERSION') {
                $info['client_side_hints'][] = 'displayTest=IGNORE_ALL_VERSION (only needed on one side, usually client)';
            }
        }

        $dependencies = $toml['dependencies'] ?? [];
        if (!is_array($dependencies)) {
            return;
        }

        foreach ($dependencies as $owner => $list) {
            if (!is_array($list)) {
                continue;
            }

            foreach ($list as $dependency) {
                if (!is_array($dependency) || !isset($dependency['modId'])) {
                    continue;
                }

                $required = isset($dependency['type'])
                    ? strtolower((string) $dependency['type']) === 'required'
                    : (bool) ($dependency['mandatory'] ?? false);

                $side = isset($dependency['side']) ? strtoupper((string) $dependency['side']) : null;
                $id = (string) $dependency['modId'];

                if ($id === 'minecraft') {
                    $info['minecraft'] ??= isset($dependency['versionRange']) ? (string) $dependency['versionRange'] : null;
                }

                // A mod that depends on the loader/minecraft only on the client is a client mod
                if (in_array($id, ['minecraft', 'neoforge', 'forge'], true) && $side === 'CLIENT' && $required) {
                    $info['client_side_hints'][] = "requires $id only on the CLIENT side";
                }

                $info['dependencies'][] = [
                    'id' => $id,
                    'required' => $required,
                    'version' => isset($dependency['versionRange']) ? (string) $dependency['versionRange'] : null,
                    'side' => $side,
                    'owner' => is_string($owner) ? $owner : null,
                ];
            }
        }
    }

    /**
     * @param  array<string, mixed>  $info
     * @param  array<string, mixed>  $data
     */
    protected static function readFabric(array &$info, array $data): void
    {
        if (!isset($data['id'])) {
            return;
        }

        $info['mods'][] = ['id' => (string) $data['id'], 'name' => $data['name'] ?? null, 'version' => isset($data['version']) ? (string) $data['version'] : null];

        $environment = $data['environment'] ?? '*';
        if (in_array($environment, ['client', 'server'], true)) {
            $info['environment'] = $environment;
            if ($environment === 'client') {
                $info['client_side_hints'][] = 'environment=client in fabric.mod.json';
            }
        }

        $entrypoints = is_array($data['entrypoints'] ?? null) ? array_keys($data['entrypoints']) : [];
        if ($entrypoints && !array_diff($entrypoints, ['client', 'modmenu', 'rei_client', 'emi', 'jei_mod_plugin', 'rei', 'iris', 'sodium:config_api_user'])) {
            $info['client_side_hints'][] = 'only has client entrypoints (' . implode(', ', $entrypoints) . ')';
        }

        foreach (['depends' => true, 'recommends' => false] as $key => $required) {
            foreach ((array) ($data[$key] ?? []) as $id => $version) {
                if ($id === 'minecraft') {
                    $info['minecraft'] ??= is_array($version) ? implode(' || ', $version) : (string) $version;
                }
                if ($id === 'fabricloader') {
                    $info['loader_version'] ??= is_array($version) ? implode(' || ', $version) : (string) $version;
                }

                $info['dependencies'][] = [
                    'id' => (string) $id,
                    'required' => $required,
                    'version' => is_array($version) ? implode(' || ', $version) : (string) $version,
                    'side' => null,
                    'owner' => (string) $data['id'],
                ];
            }
        }
    }

    /**
     * @param  array<string, mixed>  $info
     * @param  array<string, mixed>  $data
     */
    protected static function readQuilt(array &$info, array $data): void
    {
        $loader = $data['quilt_loader'] ?? [];
        if (!is_array($loader) || !isset($loader['id'])) {
            return;
        }

        $info['mods'][] = ['id' => (string) $loader['id'], 'name' => $loader['metadata']['name'] ?? null, 'version' => isset($loader['version']) ? (string) $loader['version'] : null];

        $environment = $data['minecraft']['environment'] ?? '*';
        if (in_array($environment, ['client', 'dedicated_server'], true)) {
            $info['environment'] = $environment === 'client' ? 'client' : 'server';
            if ($environment === 'client') {
                $info['client_side_hints'][] = 'environment=client in quilt.mod.json';
            }
        }

        foreach ((array) ($loader['depends'] ?? []) as $dependency) {
            $id = is_array($dependency) ? ($dependency['id'] ?? null) : $dependency;
            if (!is_string($id)) {
                continue;
            }

            $optional = is_array($dependency) && ($dependency['optional'] ?? false);
            $info['dependencies'][] = [
                'id' => $id,
                'required' => !$optional,
                'version' => is_array($dependency) && isset($dependency['versions']) ? json_encode($dependency['versions']) : null,
                'side' => null,
                'owner' => (string) $loader['id'],
            ];
        }
    }

    /** @return array<mixed> */
    protected static function json(string $json): array
    {
        // Some mods ship json with comments or trailing commas
        $json = (string) preg_replace('#^\s*//.*$#m', '', $json);
        $json = (string) preg_replace('/,\s*([\]}])/', '$1', $json);
        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }

    protected static function manifestValue(ZipArchive $zip, string $key): ?string
    {
        $manifest = $zip->getFromName('META-INF/MANIFEST.MF');
        if ($manifest === false) {
            return null;
        }

        if (preg_match('/^' . preg_quote($key, '/') . ':\s*(.+)$/mi', $manifest, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    protected static function yamlValue(string $yml, string $key): ?string
    {
        if (preg_match('/^' . preg_quote($key, '/') . ':\s*["\']?([^"\'\r\n]+)["\']?\s*$/mi', $yml, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    /** @return string[] */
    protected static function yamlList(string $yml, string $key): array
    {
        // depend: [A, B]
        if (preg_match('/^' . preg_quote($key, '/') . ':\s*\[(.*)\]\s*$/mi', $yml, $m)) {
            return array_values(array_filter(array_map(fn ($item) => trim($item, " \"'"), explode(',', $m[1]))));
        }

        // depend:
        //   - A
        if (preg_match('/^' . preg_quote($key, '/') . ':\s*\R((?:\s+-\s*.+\R?)+)/mi', $yml, $m)) {
            preg_match_all('/-\s*["\']?([^"\'\r\n]+)["\']?/', $m[1], $items);

            return array_map('trim', $items[1]);
        }

        return [];
    }
}
