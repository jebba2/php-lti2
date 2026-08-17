<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Support;

use Psr\SimpleCache\CacheInterface;

/**
 * A real, fully-functional PSR-16 cache backed by an in-process array
 * (including real TTL expiry), for tests that need a genuine cache
 * implementation without pulling in Redis/Memcached/etc. Not a mock of
 * anything under test — it's a real, if small, cache.
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, array{value: mixed, expiresAt: float|null}> */
    private array $items = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->has($key) ? $this->items[$key]['value'] : $default;
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->items[$key] = ['value' => $value, 'expiresAt' => $this->expiryTimestamp($ttl)];

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->items = [];

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

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        if (!array_key_exists($key, $this->items)) {
            return false;
        }

        $expiresAt = $this->items[$key]['expiresAt'];
        if ($expiresAt !== null && $expiresAt < microtime(true)) {
            unset($this->items[$key]);

            return false;
        }

        return true;
    }

    private function expiryTimestamp(\DateInterval|int|null $ttl): ?float
    {
        if ($ttl === null) {
            return null;
        }

        if (is_int($ttl)) {
            return microtime(true) + $ttl;
        }

        $now = new \DateTimeImmutable();

        return (float) $now->add($ttl)->getTimestamp();
    }
}
