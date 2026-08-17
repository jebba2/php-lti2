<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Unit\Support;

use PhpLti\Lti1p3\Tests\Support\Filesystem;
use PhpLti\Lti1p3\Tests\Support\FixtureStore;
use PHPUnit\Framework\TestCase;

final class FixtureStoreTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->fixtureDir = sys_get_temp_dir() . '/php-lti-fixture-store-' . bin2hex(random_bytes(8));
        mkdir($this->fixtureDir, 0700, true);
    }

    protected function tearDown(): void
    {
        Filesystem::removeDirectory($this->fixtureDir);
    }

    public function testNextResponseReturnsNullWhenNothingConfigured(): void
    {
        $response = FixtureStore::nextResponse($this->fixtureDir, 'GET', '/jwks');

        self::assertNull($response);
    }

    public function testSingleQueuedResponseIsServedRepeatedly(): void
    {
        $headers = ['Content-Type' => 'application/json'];
        FixtureStore::queueResponse($this->fixtureDir, 'GET', '/jwks', 200, $headers, '{"keys":[]}');

        $first = FixtureStore::nextResponse($this->fixtureDir, 'GET', '/jwks');
        $second = FixtureStore::nextResponse($this->fixtureDir, 'GET', '/jwks');

        self::assertSame(['status' => 200, 'headers' => $headers, 'body' => '{"keys":[]}'], $first);
        self::assertSame($first, $second);
    }

    public function testMultipleQueuedResponsesAreConsumedInOrderThenStickToTheLast(): void
    {
        FixtureStore::queueResponse($this->fixtureDir, 'POST', '/token', 500, [], 'first');
        FixtureStore::queueResponse($this->fixtureDir, 'POST', '/token', 200, [], 'second');

        $first = FixtureStore::nextResponse($this->fixtureDir, 'POST', '/token');
        $second = FixtureStore::nextResponse($this->fixtureDir, 'POST', '/token');
        $third = FixtureStore::nextResponse($this->fixtureDir, 'POST', '/token');
        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertNotNull($third);

        self::assertSame(500, $first['status']);
        self::assertSame('first', $first['body']);
        self::assertSame(200, $second['status']);
        self::assertSame('second', $second['body']);
        self::assertSame(200, $third['status']);
        self::assertSame('second', $third['body']);
    }

    public function testResponsesForDifferentRoutesAreIndependent(): void
    {
        FixtureStore::queueResponse($this->fixtureDir, 'GET', '/jwks', 200, [], 'jwks-body');
        FixtureStore::queueResponse($this->fixtureDir, 'GET', '/other', 200, [], 'other-body');

        $jwks = FixtureStore::nextResponse($this->fixtureDir, 'GET', '/jwks');
        $other = FixtureStore::nextResponse($this->fixtureDir, 'GET', '/other');
        self::assertNotNull($jwks);
        self::assertNotNull($other);

        self::assertSame('jwks-body', $jwks['body']);
        self::assertSame('other-body', $other['body']);
    }

    public function testRequestsForReturnsEmptyListWhenNoneRecorded(): void
    {
        self::assertSame([], FixtureStore::requestsFor($this->fixtureDir, 'GET', '/jwks'));
    }

    public function testRecordedRequestsAreReturnedInOrder(): void
    {
        $fixtureDir = $this->fixtureDir;
        $route = '/ags/scores';
        FixtureStore::recordRequest($fixtureDir, 'POST', $route, ['Authorization' => 'Bearer one'], 'body-1', []);
        FixtureStore::recordRequest(
            $fixtureDir,
            'POST',
            $route,
            ['Authorization' => 'Bearer two'],
            'body-2',
            ['page' => '2'],
        );

        $requests = FixtureStore::requestsFor($fixtureDir, 'POST', $route);

        self::assertCount(2, $requests);
        self::assertSame('Bearer one', $requests[0]['headers']['Authorization']);
        self::assertSame('body-1', $requests[0]['body']);
        self::assertSame([], $requests[0]['query']);
        self::assertSame('Bearer two', $requests[1]['headers']['Authorization']);
        self::assertSame('body-2', $requests[1]['body']);
        self::assertSame(['page' => '2'], $requests[1]['query']);
    }

    public function testPathSanitizationDistinguishesNestedRoutes(): void
    {
        FixtureStore::queueResponse($this->fixtureDir, 'GET', '/ags/lineitems', 200, [], 'lineitems');
        FixtureStore::queueResponse($this->fixtureDir, 'GET', '/ags/lineitems/1', 200, [], 'lineitem-1');

        $collection = FixtureStore::nextResponse($this->fixtureDir, 'GET', '/ags/lineitems');
        $single = FixtureStore::nextResponse($this->fixtureDir, 'GET', '/ags/lineitems/1');
        self::assertNotNull($collection);
        self::assertNotNull($single);

        self::assertSame('lineitems', $collection['body']);
        self::assertSame('lineitem-1', $single['body']);
    }
}
