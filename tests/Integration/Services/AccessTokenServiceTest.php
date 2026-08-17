<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Integration\Services;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use PhpLti\Lti1p3\Exception\ServiceException;
use PhpLti\Lti1p3\Registration\Registration;
use PhpLti\Lti1p3\Registration\ToolKeyPair;
use PhpLti\Lti1p3\Security\Jwt\JwksBuilder;
use PhpLti\Lti1p3\Security\Jwt\KeyPairGenerator;
use PhpLti\Lti1p3\Services\AccessTokenService;
use PhpLti\Lti1p3\Tests\Support\ArrayCache;
use PhpLti\Lti1p3\Tests\Support\FixturePlatformServer;
use PHPUnit\Framework\TestCase;

final class AccessTokenServiceTest extends TestCase
{
    private const ISSUER = 'https://example.brightspace.com';
    private const CLIENT_ID = 'client-1';

    private ?FixturePlatformServer $server = null;
    private ?ToolKeyPair $toolKeyPair = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    private function server(): FixturePlatformServer
    {
        return $this->server ??= FixturePlatformServer::start();
    }

    private function toolKeyPair(): ToolKeyPair
    {
        return $this->toolKeyPair ??= (new KeyPairGenerator())->generate('tool-kid');
    }

    private function registration(): Registration
    {
        return new Registration(
            self::ISSUER,
            self::CLIENT_ID,
            ['deployment-1'],
            self::ISSUER . '/d2l/lti/authenticate',
            $this->server()->baseUrl() . '/token',
            self::ISSUER . '/d2l/.well-known/jwks',
            [$this->toolKeyPair()],
        );
    }

    private function service(?ArrayCache $cache = null): AccessTokenService
    {
        return new AccessTokenService(new Client(), new HttpFactory(), new HttpFactory(), $cache ?? new ArrayCache());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function queueTokenResponse(array $body, int $status = 200): void
    {
        $this->server()->queueResponse('POST', '/token', $status, [], json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testReturnsTheAccessTokenFromASuccessfulResponse(): void
    {
        $this->queueTokenResponse(['access_token' => 'token-abc', 'token_type' => 'Bearer', 'expires_in' => 3600]);

        $token = $this->service()->getAccessToken($this->registration(), ['scope-a']);

        self::assertSame('token-abc', $token);
    }

    public function testSendsTheCorrectGrantTypeAndClientAssertionType(): void
    {
        $this->queueTokenResponse(['access_token' => 'token-abc', 'expires_in' => 3600]);

        $this->service()->getAccessToken($this->registration(), ['scope-a', 'scope-b']);

        $requests = $this->server()->receivedRequests('POST', '/token');
        self::assertCount(1, $requests);
        parse_str($requests[0]['body'], $sent);
        self::assertSame('client_credentials', $sent['grant_type']);
        self::assertSame('urn:ietf:params:oauth:client-assertion-type:jwt-bearer', $sent['client_assertion_type']);
        self::assertSame('scope-a scope-b', $sent['scope']);
    }

    public function testClientAssertionIsARealJwtSignedByTheToolWithCorrectClaims(): void
    {
        $this->queueTokenResponse(['access_token' => 'token-abc', 'expires_in' => 3600]);
        $registration = $this->registration();

        $this->service()->getAccessToken($registration, ['scope-a']);

        $requests = $this->server()->receivedRequests('POST', '/token');
        parse_str($requests[0]['body'], $sent);

        $clientAssertion = $sent['client_assertion'];
        self::assertIsString($clientAssertion);

        $jwks = (new JwksBuilder())->build($registration);
        $keySet = JWK::parseKeySet($jwks, 'RS256');
        $claims = JWT::decode($clientAssertion, $keySet);

        self::assertSame(self::CLIENT_ID, $claims->iss);
        self::assertSame(self::CLIENT_ID, $claims->sub);
        self::assertSame($registration->platformAuthenticationTokenUrl, $claims->aud);
        self::assertNotEmpty($claims->jti);
        self::assertIsInt($claims->iat);
        self::assertIsInt($claims->exp);
        self::assertGreaterThan($claims->iat, $claims->exp);
    }

    public function testCachesTheAccessTokenAndDoesNotRequestASecondTokenForTheSameScopes(): void
    {
        $this->queueTokenResponse(['access_token' => 'token-abc', 'expires_in' => 3600]);
        $cache = new ArrayCache();
        $service = $this->service($cache);
        $registration = $this->registration();

        $service->getAccessToken($registration, ['scope-a']);
        $service->getAccessToken($registration, ['scope-a']);

        self::assertCount(1, $this->server()->receivedRequests('POST', '/token'));
    }

    public function testRequestsANewTokenForADifferentScopeSet(): void
    {
        $this->queueTokenResponse(['access_token' => 'token-abc', 'expires_in' => 3600]);
        $this->queueTokenResponse(['access_token' => 'token-def', 'expires_in' => 3600]);
        $cache = new ArrayCache();
        $service = $this->service($cache);
        $registration = $this->registration();

        $first = $service->getAccessToken($registration, ['scope-a']);
        $second = $service->getAccessToken($registration, ['scope-b']);

        self::assertSame('token-abc', $first);
        self::assertSame('token-def', $second);
        self::assertCount(2, $this->server()->receivedRequests('POST', '/token'));
    }

    public function testDoesNotCacheBeyondTheTokensExpiryMinusSafetyMargin(): void
    {
        $this->queueTokenResponse(['access_token' => 'token-abc', 'expires_in' => 30]);
        $this->queueTokenResponse(['access_token' => 'token-def', 'expires_in' => 3600]);
        $cache = new ArrayCache();
        $service = $this->service($cache);
        $registration = $this->registration();

        $first = $service->getAccessToken($registration, ['scope-a']);
        $second = $service->getAccessToken($registration, ['scope-a']);

        self::assertSame('token-abc', $first);
        self::assertSame('token-def', $second);
        self::assertCount(2, $this->server()->receivedRequests('POST', '/token'));
    }

    public function testThrowsWhenHttpStatusIsNotSuccessful(): void
    {
        $this->queueTokenResponse(['error' => 'invalid_client'], 400);

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('HTTP status 400');

        $this->service()->getAccessToken($this->registration(), ['scope-a']);
    }

    public function testThrowsWhenResponseBodyIsNotValidJson(): void
    {
        $this->server()->queueResponse('POST', '/token', 200, [], 'not json');

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('not valid JSON');

        $this->service()->getAccessToken($this->registration(), ['scope-a']);
    }

    public function testThrowsWhenAccessTokenFieldIsMissing(): void
    {
        $this->queueTokenResponse(['token_type' => 'Bearer']);

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('access_token');

        $this->service()->getAccessToken($this->registration(), ['scope-a']);
    }
}
