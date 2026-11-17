<?php

namespace ChristianHnl\AiDebugger\Services;

use App\Models\Server;
use Exception;
use Throwable;

/**
 * Detects game, mod loader and minecraft version of a server.
 */
class ServerEnvironment
{
    /** @var array<string, mixed>|null */
    protected ?array $detected = null;

    public function __construct(protected Server $server, protected ServerFiles $files) {}

    /**
     * @return array<string, string>
     */
    public function variables(): array
    {
        $variables = [];

        try {
            foreach ($this->server->variables()->get() as $variable) {
                $value = $variable->server_value ?? $variable->default_value ?? '';
                $variables[$variable->env_variable] = (string) $value;
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        return $variables;
    }

    /**
     * @return array{loader: ?string, loader_version: ?string, minecraft: ?string, sources: string[]}
     */
    public function detect(): array
    {
        if ($this->detected !== null) {
            return $this->detected;
        }

        $loader = null;
        $loaderVersion = null;
        $minecraft = null;
        $sources = [];

        $variables = array_change_key_case($this->variables(), CASE_UPPER);

        foreach (['MINECRAFT_VERSION', 'MC_VERSION', 'VERSION', 'GAME_VERSION'] as $key) {
            if (!empty($variables[$key]) && preg_match('/^\d+\.\d+(\.\d+)?$/', $variables[$key])) {
                $minecraft = $variables[$key];
                $sources[] = "variable $key";

                break;
            }
        }

        foreach (['NEOFORGE_VERSION' => 'neoforge', 'FORGE_VERSION' => 'forge', 'FABRIC_VERSION' => 'fabric', 'FABRIC_LOADER_VERSION' => 'fabric', 'QUILT_VERSION' => 'quilt', 'LOADER_VERSION' => null] as $key => $type) {
            if (!empty($variables[$key]) && $variables[$key] !== 'latest') {
                $loader ??= $type;
                $loaderVersion = $variables[$key];
                $sources[] = "variable $key";

                break;
            }
        }

        $tags = [];
        try {
            $tags = array_map('strtolower', (array) ($this->server->egg->tags ?? []));
        } catch (Throwable) {
        }

        foreach (['neoforge', 'forge', 'fabric', 'quilt', 'paper', 'purpur', 'spigot', 'velocity', 'bungeecord'] as $type) {
            if (!$loader && (in_array($type, $tags, true) || str_contains(strtolower($this->server->egg->name ?? ''), $type))) {
                $loader = $type;
                $sources[] = 'egg';
            }
        }

        try {
            $root = collect($this->files->listOrEmpty(''))->pluck('name')->map(fn ($name) => strtolower($name));

            // NeoForge: libraries/net/neoforged/neoforge/<version>
            $neoforge = $this->files->listOrEmpty('libraries/net/neoforged/neoforge');
            if (!empty($neoforge)) {
                $loader = 'neoforge';
                $loaderVersion = collect($neoforge)->where('directory', true)->pluck('name')->sort(SORT_NATURAL)->last() ?? $loaderVersion;
                $sources[] = 'libraries/net/neoforged';

                // NeoForge 21.1.x = MC 1.21.1, 20.4.x = MC 1.20.4
                if (!$minecraft && $loaderVersion && preg_match('/^(\d+)\.(\d+)\./', $loaderVersion, $m)) {
                    $minecraft = '1.' . $m[1] . ($m[2] !== '0' ? '.' . $m[2] : '');
                }
            }

            // Forge: libraries/net/minecraftforge/forge/<mc>-<forge>
            if ($loader !== 'neoforge') {
                $forge = $this->files->listOrEmpty('libraries/net/minecraftforge/forge');
                if (!empty($forge)) {
                    $loader = 'forge';
                    $folder = collect($forge)->where('directory', true)->pluck('name')->sort(SORT_NATURAL)->last();
                    if ($folder && preg_match('/^(\d+\.\d+(?:\.\d+)?)-(.+)$/', $folder, $m)) {
                        $minecraft ??= $m[1];
                        $loaderVersion = $m[2];
                    }
                    $sources[] = 'libraries/net/minecraftforge';
                }
            }

            if (!$loader && ($root->contains('fabric-server-launch.jar') || $root->contains('.fabric') || $root->contains('fabric-server-launcher.properties'))) {
                $loader = 'fabric';
                $sources[] = 'fabric launcher';
            }

            if (!$loader && $root->contains('quilt-server-launch.jar')) {
                $loader = 'quilt';
                $sources[] = 'quilt launcher';
            }

            if (!$loader && $root->first(fn ($name) => str_starts_with($name, 'paper') && str_ends_with($name, '.jar'))) {
                $loader = 'paper';
                $sources[] = 'paper jar';
            }

            if (!$minecraft && $root->contains('server.properties')) {
                // Fabric keeps the vanilla jar in .fabric/server/<version>-server.jar or versions/<version>
                foreach ($this->files->listOrEmpty('versions') as $entry) {
                    if ($entry['directory'] && preg_match('/^\d+\.\d+(\.\d+)?$/', $entry['name'])) {
                        $minecraft = $entry['name'];
                        $sources[] = 'versions folder';
                    }
                }
            }
        } catch (Exception $exception) {
            report($exception);
        }

        return $this->detected = [
            'loader' => $loader,
            'loader_version' => $loaderVersion,
            'minecraft' => $minecraft,
            'sources' => array_values(array_unique($sources)),
        ];
    }

    /**
     * Loader names as used by Modrinth for the detected loader.
     *
     * @return string[]
     */
    public function modrinthLoaders(): array
    {
        return match ($this->detect()['loader']) {
            'neoforge' => ['neoforge'],
            'forge' => ['forge'],
            'fabric' => ['fabric'],
            'quilt' => ['quilt', 'fabric'],
            'paper', 'purpur' => ['paper', 'spigot', 'bukkit'],
            'spigot' => ['spigot', 'bukkit'],
            'velocity' => ['velocity'],
            'bungeecord' => ['bungeecord', 'waterfall'],
            default => [],
        };
    }

    /**
     * @return string[]
     */
    public function modrinthGameVersions(): array
    {
        $minecraft = $this->detect()['minecraft'];

        return $minecraft ? [$minecraft] : [];
    }
}
