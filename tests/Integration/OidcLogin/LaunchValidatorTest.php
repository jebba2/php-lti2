<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Integration\OidcLogin;

use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PhpLti\Lti1p3\Exception\InvalidLaunchException;
use PhpLti\Lti1p3\Exception\InvalidLaunchReason;
use PhpLti\Lti1p3\Message\ClaimUris;
use PhpLti\Lti1p3\Message\DeepLinking\LtiDeepLinkingRequest;
use PhpLti\Lti1p3\Message\LtiResourceLinkRequest;
use PhpLti\Lti1p3\OidcLogin\LaunchValidator;
use PhpLti\Lti1p3\OidcLogin\LoginInitiationHandler;
use PhpLti\Lti1p3\Registration\Registration;
use PhpLti\Lti1p3\Registration\ToolKeyPair;
use PhpLti\Lti1p3\Security\Jwt\JwksBuilder;
use PhpLti\Lti1p3\Security\Jwt\JwksFetcher;
use PhpLti\Lti1p3\Security\Jwt\JwtValidator;
use PhpLti\Lti1p3\Security\Jwt\KeyPairGenerator;
use PhpLti\Lti1p3\Tests\Support\ArrayCache;
use PhpLti\Lti1p3\Tests\Support\FixturePlatformServer;
use PhpLti\Lti1p3\Tests\Support\InMemoryRegistrationRepository;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\SimpleCache\CacheInterface;

final class LaunchValidatorTest extends TestCase
{
    private const ISSUER = 'https://example.brightspace.com';
    private const CLIENT_ID = 'client-1';
    private const DEPLOYMENT_ID = 'deployment-1';
    private const TOOL_REDIRECT_URI = 'https://tool.example.com/lti/launch';

    private ?FixturePlatformServer $server = null;
    private ?ToolKeyPair $platformKeyPair = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    private function server(): FixturePlatformServer
    {
        return $this->server ??= FixturePlatformServer::start();
    }

    private function platformKeyPair(): ToolKeyPair
    {
        return $this->platformKeyPair ??= (new KeyPairGenerator())->generate('platform-kid');
    }

    private function registration(): Registration
    {
        return new Registration(
            self::ISSUER,
            self::CLIENT_ID,
            [self::DEPLOYMENT_ID],
            self::ISSUER . '/d2l/lti/authenticate',
            self::ISSUER . '/core/connect/token',
            $this->server()->baseUrl() . '/jwks',
            [$this->platformKeyPair()],
        );
    }

    private function publishJwks(): void
    {
        $jwk = (new JwksBuilder())->buildJwk($this->platformKeyPair());
        $body = json_encode(['keys' => [$jwk]], JSON_THROW_ON_ERROR);
        $this->server()->queueResponse('GET', '/jwks', 200, [], $body);
    }

    /**
     * @return array{repository: InMemoryRegistrationRepository, cache: ArrayCache}
     */
    private function sharedDependencies(): array
    {
        $repository = new InMemoryRegistrationRepository();
        $repository->add($this->registration());

        return ['repository' => $repository, 'cache' => new ArrayCache()];
    }

    private function loginInitiationHandler(
        InMemoryRegistrationRepository $repository,
        CacheInterface $cache,
    ): LoginInitiationHandler {
        return new LoginInitiationHandler($repository, $cache, new Psr17Factory());
    }

    private function launchValidator(InMemoryRegistrationRepository $repository, CacheInterface $cache): LaunchValidator
    {
        $jwksFetcher = new JwksFetcher(new Client(), new HttpFactory(), new ArrayCache());
        $jwtValidator = new JwtValidator($jwksFetcher, new ArrayCache());

        return new LaunchValidator($repository, $cache, $jwtValidator);
    }

    /**
     * @return array{state: string, nonce: string}
     */
    private function performLoginInitiation(InMemoryRegistrationRepository $repository, CacheInterface $cache): array
    {
        $request = (new ServerRequest('GET', 'https://tool.example.com/lti/login'))->withQueryParams([
            'iss' => self::ISSUER,
            'login_hint' => 'user-1',
            'target_link_uri' => self::TOOL_REDIRECT_URI,
            'lti_deployment_id' => self::DEPLOYMENT_ID,
        ]);

        $response = $this->loginInitiationHandler($repository, $cache)->handle($request, self::TOOL_REDIRECT_URI);

        parse_str((string) parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $params);
        $state = $params['state'];
        $nonce = $params['nonce'];
        self::assertIsString($state);
        self::assertIsString($nonce);

        return ['state' => $state, 'nonce' => $nonce];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function issueToken(string $nonce, array $overrides = []): string
    {
        $now = time();
        $claims = array_merge([
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'exp' => $now + 300,
            'iat' => $now,
            'nonce' => $nonce,
            'sub' => 'user-1',
            ClaimUris::MESSAGE_TYPE => 'LtiResourceLinkRequest',
            ClaimUris::VERSION => '1.3.0',
            ClaimUris::DEPLOYMENT_ID => self::DEPLOYMENT_ID,
            ClaimUris::TARGET_LINK_URI => self::TOOL_REDIRECT_URI,
            ClaimUris::RESOURCE_LINK => ['id' => 'resource-1', 'title' => 'A resource'],
            ClaimUris::ROLES => ['http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor'],
        ], $overrides);

        return JWT::encode($claims, $this->platformKeyPair()->privateKey, 'RS256', $this->platformKeyPair()->kid);
    }

    private function launchRequest(string $state, string $idToken): ServerRequestInterface
    {
        return (new ServerRequest('POST', self::TOOL_REDIRECT_URI))->withParsedBody([
            'state' => $state,
            'id_token' => $idToken,
        ]);
    }

    public function testFullLoginToLaunchRoundtripProducesATypedResourceLinkRequest(): void
    {
        $this->publishJwks();
        ['repository' => $repository, 'cache' => $cache] = $this->sharedDependencies();

        ['state' => $state, 'nonce' => $nonce] = $this->performLoginInitiation($repository, $cache);
        $token = $this->issueToken($nonce);

        $result = $this->launchValidator($repository, $cache)->validate($this->launchRequest($state, $token));

        self::assertInstanceOf(LtiResourceLinkRequest::class, $result);
        self::assertSame('user-1', $result->subject);
        self::assertSame(self::DEPLOYMENT_ID, $result->deploymentId);
        self::assertSame(self::TOOL_REDIRECT_URI, $result->targetLinkUri);
        self::assertSame('resource-1', $result->resourceLink->id);
        self::assertSame('A resource', $result->resourceLink->title);
        self::assertTrue($result->roles->isInstructor());
        self::assertFalse($result->roles->isLearner());
    }

    public function testDispatchesToLtiDeepLinkingRequestWhenMessageTypeIsDeepLinking(): void
    {
        $this->publishJwks();
        ['repository' => $repository, 'cache' => $cache] = $this->sharedDependencies();

        ['state' => $state, 'nonce' => $nonce] = $this->performLoginInitiation($repository, $cache);
        $token = $this->issueToken($nonce, [
            ClaimUris::MESSAGE_TYPE => 'LtiDeepLinkingRequest',
            ClaimUris::DEEP_LINKING_SETTINGS => [
                'deep_link_return_url' => 'https://example.com/deep-link-return',
                'accept_types' => ['ltiResourceLink'],
            ],
        ]);

        $result = $this->launchValidator($repository, $cache)->validate($this->launchRequest($state, $token));

        self::assertInstanceOf(LtiDeepLinkingRequest::class, $result);
        self::assertSame('https://example.com/deep-link-return', $result->deepLinkingSettings->deepLinkReturnUrl);
    }

    public function testAnonymousLaunchWithNoSubjectIsAcceptedAndModeledAsNull(): void
    {
        $this->publishJwks();
        ['repository' => $repository, 'cache' => $cache] = $this->sharedDependencies();

        ['state' => $state, 'nonce' => $nonce] = $this->performLoginInitiation($repository, $cache);
        $token = $this->issueToken($nonce, ['sub' => null]);

        $result = $this->launchValidator($repository, $cache)->validate($this->launchRequest($state, $token));

        self::assertNull($result->subject);
    }

    public function testRejectsAnUnknownOrExpiredState(): void
    {
        $this->publishJwks();
        ['repository' => $repository, 'cache' => $cache] = $this->sharedDependencies();
        $token = $this->issueToken('irrelevant-nonce');

        try {
            $this->launchValidator($repository, $cache)->validate($this->launchRequest('bogus-state', $token));
            self::fail('Expected InvalidLaunchException.');
        } catch (InvalidLaunchException $exception) {
            self::assertSame(InvalidLaunchReason::InvalidState, $exception->reason);
        }
    }

    public function testRejectsAReplayedState(): void
    {
        $this->publishJwks();
        ['repository' => $repository, 'cache' => $cache] = $this->sharedDependencies();
        ['state' => $state, 'nonce' => $nonce] = $this->performLoginInitiation($repository, $cache);
        $token = $this->issueToken($nonce);
        $validator = $this->launchValidator($repository, $cache);

        $validator->validate($this->launchRequest($state, $token));

        try {
            $validator->validate($this->launchRequest($state, $this->issueToken($nonce)));
            self::fail('Expected InvalidLaunchException.');
        } catch (InvalidLaunchException $exception) {
            self::assertSame(InvalidLaunchReason::InvalidState, $exception->reason);
        }
    }

    public function testRejectsATokenWhoseNonceDoesNotMatchTheOneIssuedAtLogin(): void
    {
        $this->publishJwks();
        ['repository' => $repository, 'cache' => $cache] = $this->sharedDependencies();
        ['state' => $state] = $this->performLoginInitiation($repository, $cache);
        $token = $this->issueToken('a-different-nonce-entirely');

        try {
            $this->launchValidator($repository, $cache)->validate($this->launchRequest($state, $token));
            self::fail('Expected InvalidLaunchException.');
        } catch (InvalidLaunchException $exception) {
            self::assertSame(InvalidLaunchReason::InvalidState, $exception->reason);
        }
    }

    public function testRejectsATokenWhoseTargetLinkUriDoesNotMatchLoginInitiation(): void
    {
        $this->publishJwks();
        ['repository' => $repository, 'cache' => $cache] = $this->sharedDependencies();
        ['state' => $state, 'nonce' => $nonce] = $this->performLoginInitiation($repository, $cache);
        $token = $this->issueToken($nonce, [ClaimUris::TARGET_LINK_URI => 'https://tool.example.com/some-other-link']);

        try {
            $this->launchValidator($repository, $cache)->validate($this->launchRequest($state, $token));
            self::fail('Expected InvalidLaunchException.');
        } catch (InvalidLaunchException $exception) {
            self::assertSame(InvalidLaunchReason::InvalidState, $exception->reason);
        }
    }

    public function testRejectsATokenWhoseDeploymentIdDoesNotMatchLoginInitiation(): void
    {
        $this->publishJwks();
        ['repository' => $repository, 'cache' => $cache] = $this->sharedDependencies();
        ['state' => $state, 'nonce' => $nonce] = $this->performLoginInitiation($repository, $cache);
        $token = $this->issueToken($nonce, [ClaimUris::DEPLOYMENT_ID => 'some-other-deployment']);

        try {
            $this->launchValidator($repository, $cache)->validate($this->launchRequest($state, $token));
            self::fail('Expected InvalidLaunchException.');
        } catch (InvalidLaunchException $exception) {
            self::assertSame(InvalidLaunchReason::InvalidState, $exception->reason);
        }
    }

    public function testRejectsAnUnexpectedMessageType(): void
    {
        $this->publishJwks();
        ['repository' => $repository, 'cache' => $cache] = $this->sharedDependencies();
        ['state' => $state, 'nonce' => $nonce] = $this->performLoginInitiation($repository, $cache);
        $token = $this->issueToken($nonce, [ClaimUris::MESSAGE_TYPE => 'LtiSubmissionReviewRequest']);

        try {
            $this->launchValidator($repository, $cache)->validate($this->launchRequest($state, $token));
            self::fail('Expected InvalidLaunchException.');
        } catch (InvalidLaunchException $exception) {
            self::assertSame(InvalidLaunchReason::UnexpectedMessageType, $exception->reason);
        }
    }

    public function testRejectsAnUnsupportedLtiVersion(): void
    {
        $this->publishJwks();
        ['repository' => $repository, 'cache' => $cache] = $this->sharedDependencies();
        ['state' => $state, 'nonce' => $nonce] = $this->performLoginInitiation($repository, $cache);
        $token = $this->issueToken($nonce, [ClaimUris::VERSION => '1.1.0']);

        try {
            $this->launchValidator($repository, $cache)->validate($this->launchRequest($state, $token));
            self::fail('Expected InvalidLaunchException.');
        } catch (InvalidLaunchException $exception) {
            self::assertSame(InvalidLaunchReason::UnsupportedVersion, $exception->reason);
        }
    }

    public function testRejectsATokenMissingTheResourceLinkClaim(): void
    {
        $this->publishJwks();
        ['repository' => $repository, 'cache' => $cache] = $this->sharedDependencies();
        ['state' => $state, 'nonce' => $nonce] = $this->performLoginInitiation($repository, $cache);
        $token = $this->issueToken($nonce, [ClaimUris::RESOURCE_LINK => null]);

        try {
            $this->launchValidator($repository, $cache)->validate($this->launchRequest($state, $token));
            self::fail('Expected InvalidLaunchException.');
        } catch (InvalidLaunchException $exception) {
            self::assertSame(InvalidLaunchReason::MissingRequiredClaim, $exception->reason);
        }
    }

    public function testCapturesCustomParametersContextAndToolPlatform(): void
    {
        $this->publishJwks();
        ['repository' => $repository, 'cache' => $cache] = $this->sharedDependencies();
        ['state' => $state, 'nonce' => $nonce] = $this->performLoginInitiation($repository, $cache);
        $token = $this->issueToken($nonce, [
            ClaimUris::CUSTOM => ['foo' => 'bar'],
            ClaimUris::CONTEXT => ['id' => 'ctx-1', 'title' => 'Course 1', 'type' => ['CourseSection']],
            ClaimUris::TOOL_PLATFORM => ['guid' => 'platform-guid', 'name' => 'Brightspace'],
        ]);

        $result = $this->launchValidator($repository, $cache)->validate($this->launchRequest($state, $token));

        self::assertSame(['foo' => 'bar'], $result->custom);
        self::assertNotNull($result->context);
        self::assertSame('ctx-1', $result->context->id);
        self::assertNotNull($result->toolPlatform);
        self::assertSame('platform-guid', $result->toolPlatform->guid);
    }

    public function testManualSmokeCheckReturnsAResponseFromLoginInitiationSuitableForRedirect(): void
    {
        $this->publishJwks();
        ['repository' => $repository, 'cache' => $cache] = $this->sharedDependencies();

        $request = (new ServerRequest('GET', 'https://tool.example.com/lti/login'))->withQueryParams([
            'iss' => self::ISSUER,
            'login_hint' => 'user-1',
            'target_link_uri' => self::TOOL_REDIRECT_URI,
        ]);

        $response = $this->loginInitiationHandler($repository, $cache)->handle($request, self::TOOL_REDIRECT_URI);

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(302, $response->getStatusCode());
    }
}
