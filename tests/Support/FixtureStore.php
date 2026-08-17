<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Support;

/**
 * File-based control channel between a PHPUnit test process and the
 * `php -S` fixture Platform server, which runs as a separate OS process
 * with no shared PHP memory. Tests call queueResponse() to script what the
 * fixture should answer for a given route, and requestsFor() to inspect
 * what the router actually received. The router script uses nextResponse()
 * and recordRequest() on the other side of the same directory.
 *
 * All state lives under one fixture directory per FixturePlatformServer
 * instance, as plain JSON files — deliberately simple over efficient, since
 * this is test-only infrastructure serving a handful of requests per test.
 */
final class FixtureStore
{
    /**
     * @param array<string, string> $headers
     */
    public static function queueResponse(
        string $fixtureDir,
        string $method,
        string $path,
        int $status,
        array $headers,
        string $body,
    ): void {
        $file = self::responseFile($fixtureDir, $method, $path);
        $queue = self::readJsonArray($file);
        $queue[] = ['status' => $status, 'headers' => $headers, 'body' => $body];
        self::writeJson($file, $queue);
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}|null
     */
    public static function nextResponse(string $fixtureDir, string $method, string $path): ?array
    {
        $file = self::responseFile($fixtureDir, $method, $path);
        $queue = self::readJsonArray($file);

        if ($queue === []) {
            return null;
        }

        if (count($queue) > 1) {
            $next = array_shift($queue);
            self::writeJson($file, $queue);
        } else {
            $next = $queue[0];
        }

        /** @var array{status: int, headers: array<string, string>, body: string} $next */
        return $next;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     */
    public static function recordRequest(
        string $fixtureDir,
        string $method,
        string $path,
        array $headers,
        string $body,
        array $query,
    ): void {
        $directory = self::requestsDirectory($fixtureDir, $method, $path);
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $sequence = count(self::listRequestFiles($directory));
        $file = sprintf('%s/%06d.json', $directory, $sequence);
        self::writeJson($file, ['headers' => $headers, 'body' => $body, 'query' => $query]);
    }

    /**
     * @return list<array{headers: array<string, string>, body: string, query: array<string, mixed>}>
     */
    public static function requestsFor(string $fixtureDir, string $method, string $path): array
    {
        $directory = self::requestsDirectory($fixtureDir, $method, $path);
        $requests = [];

        foreach (self::listRequestFiles($directory) as $file) {
            /** @var array{headers: array<string, string>, body: string, query: array<string, mixed>} $decoded */
            $decoded = self::readJsonAssoc($directory . '/' . $file);
            $requests[] = $decoded;
        }

        return $requests;
    }

    /**
     * @return list<string>
     */
    private static function listRequestFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = scandir($directory);
        if ($files === false) {
            return [];
        }

        $files = array_values(array_filter($files, static fn (string $file): bool => str_ends_with($file, '.json')));
        sort($files);

        return $files;
    }

    private static function responseFile(string $fixtureDir, string $method, string $path): string
    {
        return $fixtureDir . '/responses/' . self::sanitize($method, $path) . '.json';
    }

    private static function requestsDirectory(string $fixtureDir, string $method, string $path): string
    {
        return $fixtureDir . '/requests/' . self::sanitize($method, $path);
    }

    public static function sanitize(string $method, string $path): string
    {
        $normalizedPath = trim($path, '/');
        $slug = preg_replace('/[^A-Za-z0-9]+/', '_', strtoupper($method) . '_' . $normalizedPath);

        return $slug === null || $slug === '' ? '_root' : trim($slug, '_');
    }

    /**
     * @return list<array{status: int, headers: array<string, string>, body: string}>
     */
    private static function readJsonArray(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $contents = file_get_contents($file);
        if ($contents === false || $contents === '') {
            return [];
        }

        /** @var list<array{status: int, headers: array<string, string>, body: string}> $decoded */
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private static function readJsonAssoc(string $file): array
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('Could not read fixture file "%s".', $file));
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private static function writeJson(string $file, mixed $data): void
    {
        $directory = dirname($file);
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        file_put_contents($file, json_encode($data, JSON_THROW_ON_ERROR));
    }
}
