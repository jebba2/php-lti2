<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Integration\Security\Jwt;

use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use PhpLti\Lti1p3\Exception\ServiceException;
use PhpLti\Lti1p3\Registration\Registration;
use PhpLti\Lti1p3\Security\Jwt\JwksBuilder;
use PhpLti\Lti1p3\Security\Jwt\JwksFetcher;
use PhpLti\Lti1p3\Security\Jwt\KeyPairGenerator;
use PhpLti\Lti1p3\Tests\Support\ArrayCache;
use PhpLti\Lti1p3\Tests\Support\FixturePlatformServer;
use PHPUnit\Framework\TestCase;

final class JwksFetcherTest extends TestCase
{
    private ?FixturePlatformServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    private function server(): FixturePlatformServer
    {
        return $this->server ??= FixturePlatformServer::start();
    }

    private function fetcher(?ArrayCache $cache = null, int $ttl = 3600): JwksFetcher
    {
        $client = new Client();
        $factory = new HttpFactory();

        return new JwksFetcher($client, $factory, $cache ?? new ArrayCache(), $ttl);
    }

    public function testFetchesAndParsesARealJwksAndTheKeyVerifiesARealSignedToken(): void
    {
        $keyPair = (new KeyPairGenerator())->generate('kid-1');
        $registration = new Registration(
            'https://example.brightspace.com',
            'client-1',
            ['deployment-1'],
            'https://example.brightspace.com/d2l/lti/authenticate',
            'https://example.brightspace.com/core/connect/token',
            'https://example.brightspace.com/d2l/.well-known/jwks',
            [$keyPair],
        );
        $jwks = (new JwksBuilder())->build($registration);
        $body = json_encode($jwks, JSON_THROW_ON_ERROR);
        $this->server()->queueResponse('GET', '/jwks', 200, ['Content-Type' => 'application/json'], $body);

        $keySet = $this->fetcher()->fetch($this->server()->baseUrl() . '/jwks');

        self::assertArrayHasKey('kid-1', $keySet);

        $token = JWT::encode(['sub' => 'user-1'], $keyPair->privateKey, 'RS256', 'kid-1');
        $decoded = JWT::decode($token, $keySet);
        self::assertSame('user-1', $decoded->sub);
    }

    public function testSendsAnAcceptJsonHeader(): void
    {
        $keyPair = (new KeyPairGenerator())->generate('kid-1');
        $jwks = (new JwksBuilder())->buildJwk($keyPair);
        $this->server()->queueResponse('GET', '/jwks', 200, [], json_encode(['keys' => [$jwks]], JSON_THROW_ON_ERROR));

        $this->fetcher()->fetch($this->server()->baseUrl() . '/jwks');

        $requests = $this->server()->receivedRequests('GET', '/jwks');
        self::assertCount(1, $requests);
        self::assertSame('application/json', $requests[0]['headers']['Accept']);
    }

    public function testCachesTheDocumentAndDoesNotIssueASecondHttpRequestWithinTtl(): void
    {
        $keyPair = (new KeyPairGenerator())->generate('kid-1');
        $jwks = (new JwksBuilder())->buildJwk($keyPair);
        $this->server()->queueResponse('GET', '/jwks', 200, [], json_encode(['keys' => [$jwks]], JSON_THROW_ON_ERROR));

        $cache = new ArrayCache();
        $fetcher = $this->fetcher($cache);

        $fetcher->fetch($this->server()->baseUrl() . '/jwks');
        $fetcher->fetch($this->server()->baseUrl() . '/jwks');

        self::assertCount(1, $this->server()->receivedRequests('GET', '/jwks'));
    }

    public function testRefetchesAfterTheCacheEntryExpires(): void
    {
        $keyPair = (new KeyPairGenerator())->generate('kid-1');
        $jwks = (new JwksBuilder())->buildJwk($keyPair);
        $this->server()->queueResponse('GET', '/jwks', 200, [], json_encode(['keys' => [$jwks]], JSON_THROW_ON_ERROR));

        $fetcher = $this->fetcher(new ArrayCache(), ttl: 0);

        $fetcher->fetch($this->server()->baseUrl() . '/jwks');
        $fetcher->fetch($this->server()->baseUrl() . '/jwks');

        self::assertCount(2, $this->server()->receivedRequests('GET', '/jwks'));
    }

    public function testThrowsWhenHttpStatusIsNotSuccessful(): void
    {
        $this->server()->queueResponse('GET', '/jwks', 500, [], 'internal error');

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('HTTP status 500');

        $this->fetcher()->fetch($this->server()->baseUrl() . '/jwks');
    }

    public function testThrowsWhenResponseBodyIsNotValidJson(): void
    {
        $this->server()->queueResponse('GET', '/jwks', 200, [], 'not json');

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('not valid JSON');

        $this->fetcher()->fetch($this->server()->baseUrl() . '/jwks');
    }

    public function testThrowsWhenResponseHasNoKeysArray(): void
    {
        $this->server()->queueResponse('GET', '/jwks', 200, [], '{}');

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('did not contain a "keys" array');

        $this->fetcher()->fetch($this->server()->baseUrl() . '/jwks');
    }

    public function testFiltersOutNonRsaKeysAndKeepsAcceptableOnes(): void
    {
        $keyPair = (new KeyPairGenerator())->generate('rsa-kid');
        $rsaJwk = (new JwksBuilder())->buildJwk($keyPair);
        $bogusOctJwk = ['kty' => 'oct', 'alg' => 'HS256', 'kid' => 'oct-kid', 'k' => 'c2VjcmV0'];
        $body = json_encode(['keys' => [$rsaJwk, $bogusOctJwk]], JSON_THROW_ON_ERROR);
        $this->server()->queueResponse('GET', '/jwks', 200, [], $body);

        $keySet = $this->fetcher()->fetch($this->server()->baseUrl() . '/jwks');

        self::assertArrayHasKey('rsa-kid', $keySet);
        self::assertArrayNotHasKey('oct-kid', $keySet);
    }

    public function testThrowsWhenNoAcceptableRsaKeysArePresent(): void
    {
        $bogusOctJwk = ['kty' => 'oct', 'alg' => 'HS256', 'kid' => 'oct-kid', 'k' => 'c2VjcmV0'];
        $body = json_encode(['keys' => [$bogusOctJwk]], JSON_THROW_ON_ERROR);
        $this->server()->queueResponse('GET', '/jwks', 200, [], $body);

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('did not contain any supported RSA/RS256 keys');

        $this->fetcher()->fetch($this->server()->baseUrl() . '/jwks');
    }
}
