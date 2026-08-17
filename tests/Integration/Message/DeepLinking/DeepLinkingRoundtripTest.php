<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Integration\Message\DeepLinking;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PhpLti\Lti1p3\Http\FormPostRenderer;
use PhpLti\Lti1p3\Message\ClaimUris;
use PhpLti\Lti1p3\Message\DeepLinking\ContentItem\LtiResourceLinkContentItem;
use PhpLti\Lti1p3\Message\DeepLinking\LtiDeepLinkingRequest;
use PhpLti\Lti1p3\Message\DeepLinking\LtiDeepLinkingResponse;
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

final class DeepLinkingRoundtripTest extends TestCase
{
    private const ISSUER = 'https://example.brightspace.com';
    private const CLIENT_ID = 'client-1';
    private const DEPLOYMENT_ID = 'deployment-1';
    private const TOOL_DEEP_LINK_URI = 'https://tool.example.com/lti/deep-link';
    private const PLATFORM_DEEP_LINK_RETURN_URL = 'https://example.brightspace.com/deep-link-return';

    private ?FixturePlatformServer $server = null;
    private ?ToolKeyPair $platformKeyPair = null;
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

    private function platformKeyPair(): ToolKeyPair
    {
        return $this->platformKeyPair ??= (new KeyPairGenerator())->generate('platform-kid');
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
            [self::DEPLOYMENT_ID],
            self::ISSUER . '/d2l/lti/authenticate',
            self::ISSUER . '/core/connect/token',
            $this->server()->baseUrl() . '/jwks',
            [$this->toolKeyPair()],
        );
    }

    public function testFullDeepLinkingRoundtripProducesAValidSignedFormPost(): void
    {
        // The fixture server plays the Platform: it publishes the *tool's*
        // signing key at /jwks, since JwtValidator verifies the inbound
        // id_token against whatever URL Registration.platformJwksUrl names
        // — in this test that's conveniently the same fixture server. The
        // id_token itself is still signed by a separate "platform" keypair.
        $jwk = (new JwksBuilder())->buildJwk($this->platformKeyPair());
        $this->server()->queueResponse('GET', '/jwks', 200, [], json_encode(['keys' => [$jwk]], JSON_THROW_ON_ERROR));

        $repository = new InMemoryRegistrationRepository();
        $repository->add($this->registration());
        $cache = new ArrayCache();

        // Step 1: login initiation.
        $loginHandler = new LoginInitiationHandler($repository, $cache, new Psr17Factory());
        $loginRequest = (new ServerRequest('GET', 'https://tool.example.com/lti/login'))->withQueryParams([
            'iss' => self::ISSUER,
            'login_hint' => 'user-1',
            'target_link_uri' => self::TOOL_DEEP_LINK_URI,
            'lti_deployment_id' => self::DEPLOYMENT_ID,
        ]);
        $redirectResponse = $loginHandler->handle($loginRequest, self::TOOL_DEEP_LINK_URI);
        parse_str((string) parse_url($redirectResponse->getHeaderLine('Location'), PHP_URL_QUERY), $authParams);
        $nonce = $authParams['nonce'];
        $state = $authParams['state'];
        self::assertIsString($nonce);
        self::assertIsString($state);

        // Step 2: platform issues a real signed Deep Linking Request id_token.
        $now = time();
        $idTokenClaims = [
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'exp' => $now + 300,
            'iat' => $now,
            'nonce' => $nonce,
            'sub' => 'instructor-1',
            ClaimUris::MESSAGE_TYPE => 'LtiDeepLinkingRequest',
            ClaimUris::VERSION => '1.3.0',
            ClaimUris::DEPLOYMENT_ID => self::DEPLOYMENT_ID,
            ClaimUris::TARGET_LINK_URI => self::TOOL_DEEP_LINK_URI,
            ClaimUris::DEEP_LINKING_SETTINGS => [
                'deep_link_return_url' => self::PLATFORM_DEEP_LINK_RETURN_URL,
                'accept_types' => ['ltiResourceLink'],
                'accept_multiple' => false,
                'data' => 'roundtrip-correlation-id',
            ],
        ];
        $platformKeyPair = $this->platformKeyPair();
        $idToken = JWT::encode($idTokenClaims, $platformKeyPair->privateKey, 'RS256', $platformKeyPair->kid);

        // Step 3: tool validates the launch.
        $jwksFetcher = new JwksFetcher(new Client(), new HttpFactory(), new ArrayCache());
        $jwtValidator = new JwtValidator($jwksFetcher, new ArrayCache());
        $launchValidator = new LaunchValidator($repository, $cache, $jwtValidator);
        $launchRequest = (new ServerRequest('POST', self::TOOL_DEEP_LINK_URI))->withParsedBody([
            'state' => $state,
            'id_token' => $idToken,
        ]);

        $deepLinkingRequest = $launchValidator->validate($launchRequest);
        self::assertInstanceOf(LtiDeepLinkingRequest::class, $deepLinkingRequest);
        $deepLinkReturnUrl = $deepLinkingRequest->deepLinkingSettings->deepLinkReturnUrl;
        self::assertSame(self::PLATFORM_DEEP_LINK_RETURN_URL, $deepLinkReturnUrl);

        // Step 4: tool builds and signs the Deep Linking Response.
        $selectedItem = new LtiResourceLinkContentItem(
            url: 'https://tool.example.com/launch/selected-item',
            title: 'Selected item',
        );
        $response = new LtiDeepLinkingResponse(
            $this->registration(),
            $deepLinkingRequest->deploymentId,
            self::ISSUER,
            [$selectedItem],
            data: $deepLinkingRequest->deepLinkingSettings->data,
        );
        $responseJwt = $response->toJwt();

        // Step 5: render the auto-submitting form_post back to the platform.
        $factory = new Psr17Factory();
        $formPostResponse = (new FormPostRenderer($factory, $factory))->render(
            $deepLinkingRequest->deepLinkingSettings->deepLinkReturnUrl,
            ['JWT' => $responseJwt],
        );

        $html = (string) $formPostResponse->getBody();
        self::assertStringContainsString('action="' . self::PLATFORM_DEEP_LINK_RETURN_URL . '"', $html);
        self::assertStringContainsString('name="JWT"', $html);
        self::assertStringNotContainsString('name="id_token"', $html);

        // Step 6: the platform verifies the response JWT against the tool's own JWKS.
        $toolJwks = (new JwksBuilder())->build($this->registration());
        $toolKeySet = JWK::parseKeySet($toolJwks, 'RS256');
        $decodedResponse = JWT::decode($responseJwt, $toolKeySet);

        self::assertSame(self::CLIENT_ID, $decodedResponse->iss);
        self::assertSame(self::ISSUER, $decodedResponse->aud);
        self::assertSame('roundtrip-correlation-id', $decodedResponse->{ClaimUris::DATA});
        self::assertCount(1, $decodedResponse->{ClaimUris::CONTENT_ITEMS});
        self::assertSame('Selected item', $decodedResponse->{ClaimUris::CONTENT_ITEMS}[0]->title);
    }
}
