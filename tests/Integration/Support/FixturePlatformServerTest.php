<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Integration\Support;

use GuzzleHttp\Client;
use PhpLti\Lti1p3\Tests\Support\FixturePlatformServer;
use PHPUnit\Framework\TestCase;

final class FixturePlatformServerTest extends TestCase
{
    /** @var list<FixturePlatformServer> */
    private array $servers = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }

        $this->servers = [];
    }

    private function startServer(): FixturePlatformServer
    {
        $server = FixturePlatformServer::start();
        $this->servers[] = $server;

        return $server;
    }

    private function client(): Client
    {
        return new Client(['http_errors' => false, 'timeout' => 5]);
    }

    public function testRespondsWithTheConfiguredResponseForARoute(): void
    {
        $server = $this->startServer();
        $server->queueResponse('GET', '/jwks', 200, ['Content-Type' => 'application/json'], '{"keys":[]}');

        $response = $this->client()->request('GET', $server->baseUrl() . '/jwks');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"keys":[]}', (string) $response->getBody());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function testReturnsNotFoundForAnUnconfiguredRoute(): void
    {
        $server = $this->startServer();

        $response = $this->client()->request('GET', $server->baseUrl() . '/never-configured');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testRecordsTheHeadersAndBodyOfReceivedRequests(): void
    {
        $server = $this->startServer();
        $server->queueResponse('POST', '/ags/scores', 200, [], '{}');

        $this->client()->request('POST', $server->baseUrl() . '/ags/scores', [
            'headers' => ['Authorization' => 'Bearer test-token'],
            'body' => '{"scoreGiven":10}',
        ]);

        $requests = $server->receivedRequests('POST', '/ags/scores');

        self::assertCount(1, $requests);
        self::assertSame('Bearer test-token', $requests[0]['headers']['Authorization']);
        self::assertSame('{"scoreGiven":10}', $requests[0]['body']);
    }

    public function testTwoServersRunIndependentlyOnDifferentPorts(): void
    {
        $first = $this->startServer();
        $second = $this->startServer();

        self::assertNotSame($first->baseUrl(), $second->baseUrl());

        $first->queueResponse('GET', '/route', 200, [], 'from-first');
        $second->queueResponse('GET', '/route', 200, [], 'from-second');

        $firstResponse = $this->client()->request('GET', $first->baseUrl() . '/route');
        $secondResponse = $this->client()->request('GET', $second->baseUrl() . '/route');

        self::assertSame('from-first', (string) $firstResponse->getBody());
        self::assertSame('from-second', (string) $secondResponse->getBody());
    }

    public function testStopTerminatesTheServerProcess(): void
    {
        $server = $this->startServer();
        $baseUrl = $server->baseUrl();

        $server->stop();

        $this->expectException(\GuzzleHttp\Exception\ConnectException::class);
        $this->client()->request('GET', $baseUrl . '/anything', ['timeout' => 2]);
    }
}
