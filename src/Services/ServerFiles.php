<?php

namespace ChristianHnl\AiDebugger\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;

/**
 * Thin wrapper around the daemon file api with path normalization and safety checks.
 */
class ServerFiles
{
    // Everything the plugin stores inside the server files lives in here.
    public const PLUGIN_DIR = '.ai-debugger';

    public function __construct(protected Server $server) {}

    public function server(): Server
    {
        return $this->server;
    }

    protected function repository(): DaemonFileRepository
    {
        return app(DaemonFileRepository::class)->setServer($this->server);
    }

    /**
     * Normalizes a path to the form "dir/file" (no leading slash, no "..").
     */
    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));

        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                throw new InvalidArgumentException("Path '$path' must not contain '..'");
            }

            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    public static function dirname(string $path): string
    {
        $path = self::normalize($path);
        $pos = strrpos($path, '/');

        return $pos === false ? '' : substr($path, 0, $pos);
    }

    public static function basename(string $path): string
    {
        $path = self::normalize($path);
        $pos = strrpos($path, '/');

        return $pos === false ? $path : substr($path, $pos + 1);
    }

    public static function join(string ...$parts): string
    {
        return self::normalize(implode('/', array_filter($parts, fn ($part) => $part !== '')));
    }

    public static function isProtected(string $path): bool
    {
        $path = self::normalize($path);

        return $path === self::PLUGIN_DIR || str_starts_with($path, self::PLUGIN_DIR . '/');
    }

    /**
     * Throws if the agent tries to modify the folder that holds the backups.
     */
    public static function assertWritable(string $path): string
    {
        $path = self::normalize($path);

        if ($path === '') {
            throw new InvalidArgumentException('The server root folder itself can not be modified.');
        }

        if (self::isProtected($path)) {
            throw new InvalidArgumentException('The folder ' . self::PLUGIN_DIR . ' is managed by the AI Debugger (backups, quarantine) and can not be modified.');
        }

        return $path;
    }

    /**
     * @return array<int, array{name: string, directory: bool, file: bool, size: int, modified: ?string, mime: ?string}>
     *
     * @throws Exception
     */
    public function list(string $path): array
    {
        $path = self::normalize($path);

        try {
            $entries = $this->repository()->getDirectory('/' . $path);
        } catch (RequestException $exception) {
            if ($exception->response->status() === 404) {
                throw new FileNotFoundException("Directory '/$path' does not exist.");
            }

            throw $exception;
        }

        if (isset($entries['error'])) {
            throw new Exception('Daemon error while listing /' . $path . ': ' . (is_string($entries['error']) ? $entries['error'] : 'unknown error'));
        }

        $result = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !isset($entry['name'])) {
                continue;
            }

            $directory = (bool) ($entry['directory'] ?? false) || (($entry['file'] ?? true) === false && ($entry['mime'] ?? '') === 'inode/directory');

            $result[] = [
                'name' => (string) $entry['name'],
                'directory' => $directory,
                'file' => !$directory,
                'size' => (int) ($entry['size'] ?? 0),
                'modified' => $entry['modified'] ?? $entry['modified_at'] ?? null,
                'mime' => $entry['mime'] ?? null,
            ];
        }

        usort($result, fn ($a, $b) => [$b['directory'], strtolower($a['name'])] <=> [$a['directory'], strtolower($b['name'])]);

        return $result;
    }

    /**
     * Lists a directory, returning an empty array if it doesn't exist.
     *
     * @return array<int, array{name: string, directory: bool, file: bool, size: int, modified: ?string, mime: ?string}>
     */
    public function listOrEmpty(string $path): array
    {
        try {
            return $this->list($path);
        } catch (FileNotFoundException) {
            return [];
        }
    }

    public function exists(string $path): bool
    {
        $path = self::normalize($path);
        if ($path === '') {
            return true;
        }

        foreach ($this->listOrEmpty(self::dirname($path)) as $entry) {
            if ($entry['name'] === self::basename($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{name: string, directory: bool, file: bool, size: int, modified: ?string, mime: ?string}|null
     */
    public function stat(string $path): ?array
    {
        $path = self::normalize($path);

        foreach ($this->listOrEmpty(self::dirname($path)) as $entry) {
            if ($entry['name'] === self::basename($path)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @throws Exception
     */
    public function read(string $path, ?int $maxBytes = null): string
    {
        return $this->repository()->getContent('/' . self::normalize($path), $maxBytes);
    }

    /**
     * Downloads a (binary) file to a local temporary file and returns its path.
     *
     * @throws Exception
     */
    public function download(string $path, string $target): void
    {
        $path = self::normalize($path);

        $response = $this->repository()
            ->getHttpClient()
            ->timeout(max((int) config('panel.guzzle.timeout', 15), 300))
            ->withOptions(['sink' => $target])
            ->get("/api/servers/{$this->server->uuid}/files/contents", ['file' => '/' . $path]);

        if ($response->failed()) {
            throw new Exception("Could not download /$path (HTTP {$response->status()})");
        }
    }

    /**
     * @throws Exception
     */
    public function write(string $path, string $content): void
    {
        $response = $this->repository()->putContent('/' . self::normalize($path), $content);

        if ($response->failed()) {
            throw new Exception('Could not write /' . self::normalize($path) . " (HTTP {$response->status()})");
        }
    }

    /**
     * Creates the given folder (and its parents) if missing.
     *
     * @throws Exception
     */
    public function makeDirectory(string $path): void
    {
        $path = self::normalize($path);
        if ($path === '') {
            return;
        }

        $current = '';
        foreach (explode('/', $path) as $part) {
            $next = self::join($current, $part);

            if (!$this->exists($next)) {
                try {
                    $this->repository()->createDirectory($part, '/' . $current);
                } catch (Exception $exception) {
                    // Another process may have created it in the meantime
                    if (!$this->exists($next)) {
                        throw $exception;
                    }
                }
            }

            $current = $next;
        }
    }

    /**
     * Moves/renames a file or folder.
     *
     * @throws Exception
     */
    public function move(string $from, string $to): void
    {
        $from = self::normalize($from);
        $to = self::normalize($to);

        $this->makeDirectory(self::dirname($to));

        $response = $this->repository()->renameFiles('/', [['from' => $from, 'to' => $to]]);

        if ($response->failed()) {
            throw new Exception("Could not move /$from to /$to (HTTP {$response->status()})");
        }
    }

    /**
     * @param  string[]  $paths
     *
     * @throws Exception
     */
    public function delete(array $paths): void
    {
        $paths = array_values(array_filter(array_map(fn ($path) => self::normalize($path), $paths)));
        if (empty($paths)) {
            return;
        }

        $response = $this->repository()->deleteFiles('/', $paths);

        if ($response->failed()) {
            throw new Exception('Could not delete ' . implode(', ', $paths) . " (HTTP {$response->status()})");
        }
    }

    /**
     * Compresses the given entries of a folder into an archive and returns the archive name (inside $root).
     *
     * @param  string[]  $files
     *
     * @throws Exception
     */
    public function compress(string $root, array $files, string $name): string
    {
        $result = $this->repository()->compressFiles('/' . self::normalize($root), $files, $name, 'tar.gz');

        $archive = $result['name'] ?? $result['attributes']['name'] ?? null;
        if (!is_string($archive) || $archive === '') {
            throw new Exception('Daemon did not return the name of the created archive.');
        }

        return $archive;
    }

    /**
     * Extracts an archive into the given folder.
     *
     * @throws Exception
     */
    public function decompress(string $root, string $file): void
    {
        $response = $this->repository()->decompressFile('/' . self::normalize($root), self::normalize($file));

        if ($response->failed()) {
            throw new Exception("Could not extract $file (HTTP {$response->status()})");
        }
    }

    /**
     * Lets the daemon download a file from an url and waits until it is on disk.
     *
     * @throws Exception
     */
    public function pull(string $url, string $folder, string $filename): void
    {
        $folder = self::normalize($folder);

        $this->makeDirectory($folder);

        $this->repository()
            ->getHttpClient()
            ->timeout(max((int) config('panel.guzzle.timeout', 15), 300))
            ->post("/api/servers/{$this->server->uuid}/files/pull", [
                'url' => $url,
                'root' => '/' . $folder,
                'file_name' => $filename,
                'foreground' => true,
            ]);

        if (!$this->exists(self::join($folder, $filename))) {
            throw new Exception("Download of $url finished but /$folder/$filename is missing.");
        }
    }

    /**
     * @return array<mixed>
     *
     * @throws Exception
     */
    public function search(string $pattern, string $directory): array
    {
        return $this->repository()->search($pattern, '/' . self::normalize($directory));
    }
}
