<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Unit\Support;

use PhpLti\Lti1p3\Tests\Support\ArrayCache;
use PHPUnit\Framework\TestCase;

final class ArrayCacheTest extends TestCase
{
    public function testGetReturnsDefaultWhenKeyIsMissing(): void
    {
        $cache = new ArrayCache();

        self::assertSame('default', $cache->get('missing', 'default'));
        self::assertNull($cache->get('missing'));
    }

    public function testSetThenGetReturnsTheStoredValue(): void
    {
        $cache = new ArrayCache();

        self::assertTrue($cache->set('key', ['keys' => []]));
        self::assertSame(['keys' => []], $cache->get('key'));
    }

    public function testHasReflectsWhetherAKeyIsPresent(): void
    {
        $cache = new ArrayCache();

        self::assertFalse($cache->has('key'));
        $cache->set('key', 'value');
        self::assertTrue($cache->has('key'));
    }

    public function testDeleteRemovesAKey(): void
    {
        $cache = new ArrayCache();
        $cache->set('key', 'value');

        self::assertTrue($cache->delete('key'));
        self::assertFalse($cache->has('key'));
    }

    public function testClearRemovesAllKeys(): void
    {
        $cache = new ArrayCache();
        $cache->set('a', 1);
        $cache->set('b', 2);

        self::assertTrue($cache->clear());
        self::assertFalse($cache->has('a'));
        self::assertFalse($cache->has('b'));
    }

    public function testItemExpiresAfterItsIntegerTtlElapses(): void
    {
        $cache = new ArrayCache();
        $cache->set('key', 'value', -1);

        self::assertFalse($cache->has('key'));
        self::assertNull($cache->get('key'));
    }

    public function testItemWithNoTtlNeverExpires(): void
    {
        $cache = new ArrayCache();
        $cache->set('key', 'value');

        self::assertTrue($cache->has('key'));
    }

    public function testItemExpiresAfterADateIntervalTtlElapses(): void
    {
        $cache = new ArrayCache();
        $cache->set('key', 'value', new \DateInterval('PT0S'));

        usleep(10_000);

        self::assertFalse($cache->has('key'));
    }

    public function testGetMultipleReturnsStoredValuesAndDefaultsForMissingKeys(): void
    {
        $cache = new ArrayCache();
        $cache->set('a', 1);

        $result = $cache->getMultiple(['a', 'b'], 'default');

        self::assertSame(['a' => 1, 'b' => 'default'], iterator_to_array($this->toGenerator($result)));
    }

    public function testSetMultipleStoresEveryPair(): void
    {
        $cache = new ArrayCache();

        self::assertTrue($cache->setMultiple(['a' => 1, 'b' => 2]));
        self::assertSame(1, $cache->get('a'));
        self::assertSame(2, $cache->get('b'));
    }

    public function testDeleteMultipleRemovesEveryKey(): void
    {
        $cache = new ArrayCache();
        $cache->setMultiple(['a' => 1, 'b' => 2]);

        self::assertTrue($cache->deleteMultiple(['a', 'b']));
        self::assertFalse($cache->has('a'));
        self::assertFalse($cache->has('b'));
    }

    /**
     * @param iterable<string, mixed> $iterable
     * @return \Generator<string, mixed>
     */
    private function toGenerator(iterable $iterable): \Generator
    {
        yield from $iterable;
    }
}
