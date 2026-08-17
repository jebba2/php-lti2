<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3Example;

use Psr\SimpleCache\CacheInterface;

/**
 * A real, file-backed PSR-16 cache. Needed (not just convenient) here:
 * login-initiation and launch are two separate HTTP requests, each handled
 * by a fresh PHP process under `php -S`, so an in-memory-only cache would
 * never see the state/nonce it stored on the previous request.
 */
final class FileCache implements CacheInterface
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0700, true);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $record = $this->readRecord($key);

        return $record !== null ? $record['value'] : $default;
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $record = ['value' => $value, 'expiresAt' => $this->expiryTimestamp($ttl)];

        return file_put_contents($this->pathFor($key), serialize($record)) !== false;
    }

    public function delete(string $key): bool
    {
        $path = $this->pathFor($key);
        if (is_file($path)) {
            unlink($path);
        }

        return true;
    }

    public function clear(): bool
    {
        foreach (glob($this->directory . '/*.cache') ?: [] as $file) {
            unlink($file);
        }

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return $this->readRecord($key) !== null;
    }

    /**
     * @return array{value: mixed, expiresAt: int|null}|null
     */
    private function readRecord(string $key): ?array
    {
        $path = $this->pathFor($key);
        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $record = @unserialize($contents, ['allowed_classes' => true]);
        if (!is_array($record) || !array_key_exists('value', $record) || !array_key_exists('expiresAt', $record)) {
            return null;
        }

        /** @var array{value: mixed, expiresAt: int|null} $record */
        if ($record['expiresAt'] !== null && $record['expiresAt'] < time()) {
            unlink($path);

            return null;
        }

        return $record;
    }

    private function pathFor(string $key): string
    {
        return $this->directory . '/' . hash('sha256', $key) . '.cache';
    }

    private function expiryTimestamp(\DateInterval|int|null $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if (is_int($ttl)) {
            return time() + $ttl;
        }

        return (new \DateTimeImmutable())->add($ttl)->getTimestamp();
    }
}
