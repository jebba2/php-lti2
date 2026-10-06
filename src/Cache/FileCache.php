<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Cache;

use Psr\SimpleCache\CacheInterface;

/**
 * A file-backed PSR-16 cache, one file per key, for applications that
 * don't already have a cache backend. Login initiation and launch arrive
 * as two separate HTTP requests, usually handled by different PHP
 * processes, so the cache this library is given has to outlive a single
 * request. This one does that with nothing but a directory on disk.
 *
 * Values are stored with serialize(), so the directory must be writable
 * only by the application itself.
 */
final class FileCache implements CacheInterface
{
    /**
     * @param string $directory Where cache files are kept. Created with mode 0700 if missing.
     */
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0700, true);
        }
    }

    /**
     * Returns the stored value, or $default when the key is missing or expired.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $record = $this->readRecord($key);

        return $record !== null ? $record['value'] : $default;
    }

    /**
     * Stores a value. A null $ttl keeps it until deleted; a zero or negative
     * $ttl stores it already expired, so it reads back as missing.
     */
    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $record = ['value' => $value, 'expiresAt' => $this->expiryTimestamp($ttl)];

        return $this->writeAtomically($this->pathFor($key), serialize($record));
    }

    /**
     * Removes a key. Deleting a key that isn't there counts as success.
     */
    public function delete(string $key): bool
    {
        $this->removeFile($this->pathFor($key));

        return true;
    }

    /**
     * Removes every key in this cache directory.
     */
    public function clear(): bool
    {
        foreach ($this->cacheFiles() as $path) {
            $this->removeFile($path);
        }

        return true;
    }

    /**
     * Deletes the files of expired items and returns how many were removed.
     *
     * An expired item is otherwise only deleted when something reads its
     * key again, and a nonce is never read again unless a launch is
     * replayed, so every launch leaves one small file behind. Call this
     * from a scheduled job to keep the directory from growing.
     */
    public function removeExpired(): int
    {
        $removed = 0;
        foreach ($this->cacheFiles() as $path) {
            $record = $this->readRecordFile($path);
            if ($record !== null && $this->hasExpired($record)) {
                $this->removeFile($path);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * @param iterable<string> $keys
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }

        return true;
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    /**
     * Reports whether a key is present and not expired.
     */
    public function has(string $key): bool
    {
        return $this->readRecord($key) !== null;
    }

    /**
     * Returns the live record for a key, deleting its file if it has expired.
     *
     * @return array{value: mixed, expiresAt: int|null}|null
     */
    private function readRecord(string $key): ?array
    {
        $path = $this->pathFor($key);
        $record = $this->readRecordFile($path);
        if ($record === null) {
            return null;
        }

        if ($this->hasExpired($record)) {
            $this->removeFile($path);

            return null;
        }

        return $record;
    }

    /**
     * Parses one cache file, whether or not its record has expired.
     * Returns null when the file is missing or isn't a cache record.
     *
     * @return array{value: mixed, expiresAt: int|null}|null
     */
    private function readRecordFile(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        // Another process can delete the file between the check above and
        // this read; that is a cache miss, not an error worth a warning.
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $record = @unserialize($contents, ['allowed_classes' => true]);
        if (!is_array($record) || !array_key_exists('value', $record) || !array_key_exists('expiresAt', $record)) {
            return null;
        }

        /** @var array{value: mixed, expiresAt: int|null} $record */
        return $record;
    }

    /**
     * @param array{value: mixed, expiresAt: int|null} $record
     */
    private function hasExpired(array $record): bool
    {
        return $record['expiresAt'] !== null && $record['expiresAt'] <= time();
    }

    /**
     * Writes to a temporary file and renames it into place, so a reader in
     * another process sees either the old record or the new one, never a
     * partly written file.
     */
    private function writeAtomically(string $path, string $contents): bool
    {
        $temporaryPath = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (file_put_contents($temporaryPath, $contents) === false) {
            return false;
        }

        if (!rename($temporaryPath, $path)) {
            $this->removeFile($temporaryPath);

            return false;
        }

        return true;
    }

    /**
     * Deletes a cache file if it is still there. Two requests can try to
     * remove the same expired record at once; the loser's "no such file"
     * warning is suppressed because the file being gone is the goal.
     */
    private function removeFile(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @return list<string>
     */
    private function cacheFiles(): array
    {
        $files = glob($this->directory . '/*.cache');

        return $files === false ? [] : $files;
    }

    private function pathFor(string $key): string
    {
        return $this->directory . '/' . hash('sha256', $key) . '.cache';
    }

    /**
     * Converts a PSR-16 TTL into the Unix timestamp at which the item
     * expires, or null when it never does.
     */
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
