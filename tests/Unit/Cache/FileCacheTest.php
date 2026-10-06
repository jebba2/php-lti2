<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Unit\Cache;

use PhpLti\Lti1p3\Cache\FileCache;
use PhpLti\Lti1p3\Tests\Support\Filesystem;
use PHPUnit\Framework\TestCase;

final class FileCacheTest extends TestCase
{
    private string $cacheDirectory;

    protected function setUp(): void
    {
        $this->cacheDirectory = sys_get_temp_dir() . '/php-lti-file-cache-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        Filesystem::removeDirectory($this->cacheDirectory);
    }

    public function testCreatesTheCacheDirectoryWhenItIsMissing(): void
    {
        $nestedDirectory = $this->cacheDirectory . '/nested/cache';

        new FileCache($nestedDirectory);

        self::assertDirectoryExists($nestedDirectory);
    }

    public function testGetReturnsDefaultWhenKeyIsMissing(): void
    {
        $cache = new FileCache($this->cacheDirectory);

        self::assertSame('default', $cache->get('missing', 'default'));
        self::assertNull($cache->get('missing'));
    }

    public function testSetThenGetReturnsTheStoredValue(): void
    {
        $cache = new FileCache($this->cacheDirectory);

        self::assertTrue($cache->set('key', ['keys' => []]));
        self::assertSame(['keys' => []], $cache->get('key'));
    }

    public function testSetReplacesAnExistingValue(): void
    {
        $cache = new FileCache($this->cacheDirectory);
        $cache->set('key', 'first');

        $cache->set('key', 'second');

        self::assertSame('second', $cache->get('key'));
    }

    public function testStoredValueIsVisibleToASecondCacheOnTheSameDirectory(): void
    {
        (new FileCache($this->cacheDirectory))->set('key', 'value');

        self::assertSame('value', (new FileCache($this->cacheDirectory))->get('key'));
    }

    public function testSetLeavesExactlyOneFilePerKey(): void
    {
        $cache = new FileCache($this->cacheDirectory);

        $cache->set('key', 'first');
        $cache->set('key', 'second');

        self::assertCount(1, $this->filesInCacheDirectory());
    }

    public function testHasReflectsWhetherAKeyIsPresent(): void
    {
        $cache = new FileCache($this->cacheDirectory);

        self::assertFalse($cache->has('key'));
        $cache->set('key', 'value');
        self::assertTrue($cache->has('key'));
    }

    public function testDeleteRemovesAKey(): void
    {
        $cache = new FileCache($this->cacheDirectory);
        $cache->set('key', 'value');

        self::assertTrue($cache->delete('key'));
        self::assertFalse($cache->has('key'));
    }

    public function testDeleteOfAMissingKeySucceeds(): void
    {
        $cache = new FileCache($this->cacheDirectory);

        self::assertTrue($cache->delete('missing'));
    }

    public function testClearRemovesAllKeys(): void
    {
        $cache = new FileCache($this->cacheDirectory);
        $cache->set('a', 1);
        $cache->set('b', 2);

        self::assertTrue($cache->clear());
        self::assertFalse($cache->has('a'));
        self::assertFalse($cache->has('b'));
    }

    public function testItemWithNoTtlNeverExpires(): void
    {
        $cache = new FileCache($this->cacheDirectory);
        $cache->set('key', 'value');

        self::assertTrue($cache->has('key'));
    }

    public function testItemWithAFutureIntegerTtlIsReadable(): void
    {
        $cache = new FileCache($this->cacheDirectory);
        $cache->set('key', 'value', 60);

        self::assertSame('value', $cache->get('key'));
    }

    public function testItemWithANegativeIntegerTtlIsAlreadyExpired(): void
    {
        $cache = new FileCache($this->cacheDirectory);
        $cache->set('key', 'value', -1);

        self::assertFalse($cache->has('key'));
        self::assertNull($cache->get('key'));
    }

    public function testItemWithAZeroTtlIsAlreadyExpired(): void
    {
        $cache = new FileCache($this->cacheDirectory);
        $cache->set('key', 'value', 0);

        self::assertFalse($cache->has('key'));
    }

    public function testItemWithAFutureDateIntervalTtlIsReadable(): void
    {
        $cache = new FileCache($this->cacheDirectory);
        $cache->set('key', 'value', new \DateInterval('PT60S'));

        self::assertSame('value', $cache->get('key'));
    }

    public function testItemWithAnElapsedDateIntervalTtlIsExpired(): void
    {
        $elapsed = new \DateInterval('PT60S');
        $elapsed->invert = 1;

        $cache = new FileCache($this->cacheDirectory);
        $cache->set('key', 'value', $elapsed);

        self::assertFalse($cache->has('key'));
    }

    public function testReadingAnExpiredItemRemovesItsFile(): void
    {
        $cache = new FileCache($this->cacheDirectory);
        $cache->set('key', 'value', -1);

        $cache->get('key');

        self::assertSame([], $this->filesInCacheDirectory());
    }

    public function testUnreadableCacheFileIsTreatedAsAMiss(): void
    {
        $cache = new FileCache($this->cacheDirectory);
        $cache->set('key', 'value');
        file_put_contents($this->filesInCacheDirectory()[0], 'not a cache record');

        self::assertSame('default', $cache->get('key', 'default'));
    }

    public function testRemoveExpiredDeletesOnlyExpiredItemsAndReportsHowMany(): void
    {
        $cache = new FileCache($this->cacheDirectory);
        $cache->set('expired-a', 1, -1);
        $cache->set('expired-b', 2, -1);
        $cache->set('fresh', 3, 60);
        $cache->set('permanent', 4);

        self::assertSame(2, $cache->removeExpired());
        self::assertCount(2, $this->filesInCacheDirectory());
        self::assertSame(3, $cache->get('fresh'));
        self::assertSame(4, $cache->get('permanent'));
    }

    public function testGetMultipleReturnsStoredValuesAndDefaultsForMissingKeys(): void
    {
        $cache = new FileCache($this->cacheDirectory);
        $cache->set('a', 1);

        $result = $cache->getMultiple(['a', 'b'], 'default');

        self::assertSame(['a' => 1, 'b' => 'default'], iterator_to_array($this->toGenerator($result)));
    }

    public function testSetMultipleStoresEveryPair(): void
    {
        $cache = new FileCache($this->cacheDirectory);

        self::assertTrue($cache->setMultiple(['a' => 1, 'b' => 2]));
        self::assertSame(1, $cache->get('a'));
        self::assertSame(2, $cache->get('b'));
    }

    public function testDeleteMultipleRemovesEveryKey(): void
    {
        $cache = new FileCache($this->cacheDirectory);
        $cache->setMultiple(['a' => 1, 'b' => 2]);

        self::assertTrue($cache->deleteMultiple(['a', 'b']));
        self::assertFalse($cache->has('a'));
        self::assertFalse($cache->has('b'));
    }

    /**
     * @return list<string>
     */
    private function filesInCacheDirectory(): array
    {
        $files = glob($this->cacheDirectory . '/*');

        return $files === false ? [] : $files;
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
