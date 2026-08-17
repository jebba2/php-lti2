<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Unit\OidcLogin;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PhpLti\Lti1p3\Cache\CacheKeyBuilder;
use PhpLti\Lti1p3\Exception\InvalidLoginInitiationException;
use PhpLti\Lti1p3\Exception\RegistrationNotFoundException;
use PhpLti\Lti1p3\OidcLogin\LoginInitiationHandler;
use PhpLti\Lti1p3\Registration\Registration;
use PhpLti\Lti1p3\Registration\ToolKeyPair;
use PhpLti\Lti1p3\Tests\Support\ArrayCache;
use PhpLti\Lti1p3\Tests\Support\InMemoryRegistrationRepository;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class LoginInitiationHandlerTest extends TestCase
{
    private const ISSUER = 'https://example.brightspace.com';
    private const CLIENT_ID = 'client-1';
    private const REDIRECT_URI = 'https://tool.example.com/lti/launch';

    private function registration(): Registration
    {
        return new Registration(
            self::ISSUER,
            self::CLIENT_ID,
            ['deployment-1'],
            self::ISSUER . '/d2l/lti/authenticate',
            self::ISSUER . '/core/connect/token',
            self::ISSUER . '/d2l/.well-known/jwks',
            [new ToolKeyPair('kid-1', 'priv', 'pub')],
        );
    }

    private function repositoryWithRegistration(): InMemoryRegistrationRepository
    {
        $repository = new InMemoryRegistrationRepository();
        $repository->add($this->registration());

        return $repository;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function requestWithQuery(array $query): ServerRequestInterface
    {
        return (new ServerRequest('GET', 'https://tool.example.com/lti/login'))->withQueryParams($query);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function requestWithParsedBody(array $body): ServerRequestInterface
    {
        return (new ServerRequest('POST', 'https://tool.example.com/lti/login'))->withParsedBody($body);
    }

    private function handler(
        ?InMemoryRegistrationRepository $repository = null,
        ?ArrayCache $cache = null,
    ): LoginInitiationHandler {
        return new LoginInitiationHandler(
            $repository ?? $this->repositoryWithRegistration(),
            $cache ?? new ArrayCache(),
            new Psr17Factory(),
        );
    }

    /**
     * @return array<string, string>
     */
    private function queryParamsFromLocation(ResponseInterface $response): array
    {
        parse_str((string) parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $params);

        $result = [];
        foreach ($params as $key => $value) {
            self::assertIsString($key);
            self::assertIsString($value);
            $result[$key] = $value;
        }

        return $result;
    }

    public function testBuildsARedirectToThePlatformsAuthenticationEndpoint(): void
    {
        $request = $this->requestWithQuery([
            'iss' => self::ISSUER,
            'login_hint' => 'user-1',
            'target_link_uri' => self::REDIRECT_URI,
        ]);

        $response = $this->handler()->handle($request, self::REDIRECT_URI);

        self::assertSame(302, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        self::assertStringStartsWith(self::ISSUER . '/d2l/lti/authenticate?', $location);

        $params = $this->queryParamsFromLocation($response);
        self::assertSame('openid', $params['scope']);
        self::assertSame('id_token', $params['response_type']);
        self::assertSame(self::CLIENT_ID, $params['client_id']);
        self::assertSame(self::REDIRECT_URI, $params['redirect_uri']);
        self::assertSame('user-1', $params['login_hint']);
        self::assertSame('form_post', $params['response_mode']);
        self::assertSame('none', $params['prompt']);
        self::assertNotEmpty($params['state']);
        self::assertNotEmpty($params['nonce']);
    }

    public function testPassesThroughLtiMessageHintWhenPresent(): void
    {
        $request = $this->requestWithQuery([
            'iss' => self::ISSUER,
            'login_hint' => 'user-1',
            'target_link_uri' => self::REDIRECT_URI,
            'lti_message_hint' => 'hint-value',
        ]);

        $response = $this->handler()->handle($request, self::REDIRECT_URI);

        $params = $this->queryParamsFromLocation($response);
        self::assertSame('hint-value', $params['lti_message_hint']);
    }

    public function testOmitsLtiMessageHintWhenAbsent(): void
    {
        $request = $this->requestWithQuery([
            'iss' => self::ISSUER,
            'login_hint' => 'user-1',
            'target_link_uri' => self::REDIRECT_URI,
        ]);

        $response = $this->handler()->handle($request, self::REDIRECT_URI);

        $params = $this->queryParamsFromLocation($response);
        self::assertArrayNotHasKey('lti_message_hint', $params);
    }

    public function testStoresStateNonceTargetLinkUriAndDeploymentIdInTheCache(): void
    {
        $cache = new ArrayCache();
        $request = $this->requestWithQuery([
            'iss' => self::ISSUER,
            'login_hint' => 'user-1',
            'target_link_uri' => self::REDIRECT_URI,
            'lti_deployment_id' => 'deployment-1',
        ]);

        $response = $this->handler(cache: $cache)->handle($request, self::REDIRECT_URI);

        $params = $this->queryParamsFromLocation($response);
        $stored = $cache->get(CacheKeyBuilder::build('login-state', $params['state']));

        self::assertIsArray($stored);
        self::assertSame($params['nonce'], $stored['nonce']);
        self::assertSame(self::REDIRECT_URI, $stored['target_link_uri']);
        self::assertSame('deployment-1', $stored['deployment_id']);
        self::assertSame(self::ISSUER, $stored['issuer']);
        self::assertSame(self::CLIENT_ID, $stored['client_id']);
    }

    public function testDeploymentIdIsNullInCacheWhenNotSent(): void
    {
        $cache = new ArrayCache();
        $request = $this->requestWithQuery([
            'iss' => self::ISSUER,
            'login_hint' => 'user-1',
            'target_link_uri' => self::REDIRECT_URI,
        ]);

        $response = $this->handler(cache: $cache)->handle($request, self::REDIRECT_URI);

        $params = $this->queryParamsFromLocation($response);
        $stored = $cache->get(CacheKeyBuilder::build('login-state', $params['state']));

        self::assertIsArray($stored);
        self::assertNull($stored['deployment_id']);
    }

    public function testWorksWithAPostFormEncodedBodyInsteadOfQueryParams(): void
    {
        $request = $this->requestWithParsedBody([
            'iss' => self::ISSUER,
            'login_hint' => 'user-1',
            'target_link_uri' => self::REDIRECT_URI,
        ]);

        $response = $this->handler()->handle($request, self::REDIRECT_URI);

        self::assertSame(302, $response->getStatusCode());
    }

    public function testThrowsWhenIssIsMissing(): void
    {
        $request = $this->requestWithQuery(['login_hint' => 'user-1', 'target_link_uri' => self::REDIRECT_URI]);

        $this->expectException(InvalidLoginInitiationException::class);

        $this->handler()->handle($request, self::REDIRECT_URI);
    }

    public function testThrowsWhenLoginHintIsMissing(): void
    {
        $request = $this->requestWithQuery(['iss' => self::ISSUER, 'target_link_uri' => self::REDIRECT_URI]);

        $this->expectException(InvalidLoginInitiationException::class);

        $this->handler()->handle($request, self::REDIRECT_URI);
    }

    public function testThrowsWhenTargetLinkUriIsMissing(): void
    {
        $request = $this->requestWithQuery(['iss' => self::ISSUER, 'login_hint' => 'user-1']);

        $this->expectException(InvalidLoginInitiationException::class);

        $this->handler()->handle($request, self::REDIRECT_URI);
    }

    public function testThrowsWhenTargetLinkUriIsPlainHttpToARemoteHost(): void
    {
        $request = $this->requestWithQuery([
            'iss' => self::ISSUER,
            'login_hint' => 'user-1',
            'target_link_uri' => 'http://tool.example.com/lti/launch',
        ]);

        $this->expectException(InvalidLoginInitiationException::class);
        $this->expectExceptionMessage('target_link_uri');

        $this->handler()->handle($request, self::REDIRECT_URI);
    }

    public function testThrowsWhenRedirectUriIsPlainHttpToARemoteHost(): void
    {
        $request = $this->requestWithQuery([
            'iss' => self::ISSUER,
            'login_hint' => 'user-1',
            'target_link_uri' => self::REDIRECT_URI,
        ]);

        $this->expectException(InvalidLoginInitiationException::class);
        $this->expectExceptionMessage('redirect_uri');

        $this->handler()->handle($request, 'http://tool.example.com/lti/launch');
    }

    public function testThrowsWhenNoRegistrationMatches(): void
    {
        $request = $this->requestWithQuery([
            'iss' => 'https://unknown.example.com',
            'login_hint' => 'user-1',
            'target_link_uri' => self::REDIRECT_URI,
        ]);

        $this->expectException(RegistrationNotFoundException::class);

        $this->handler(new InMemoryRegistrationRepository())->handle($request, self::REDIRECT_URI);
    }
}
