<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Integration\Security\Jwt;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use PhpLti\Lti1p3\Exception\InvalidLaunchException;
use PhpLti\Lti1p3\Exception\InvalidLaunchReason;
use PhpLti\Lti1p3\Registration\Registration;
use PhpLti\Lti1p3\Registration\ToolKeyPair;
use PhpLti\Lti1p3\Security\Jwt\JwksBuilder;
use PhpLti\Lti1p3\Security\Jwt\JwksFetcher;
use PhpLti\Lti1p3\Security\Jwt\JwtValidator;
use PhpLti\Lti1p3\Security\Jwt\KeyPairGenerator;
use PhpLti\Lti1p3\Tests\Support\ArrayCache;
use PhpLti\Lti1p3\Tests\Support\FixturePlatformServer;
use PHPUnit\Framework\TestCase;

final class JwtValidatorTest extends TestCase
{
    private const ISSUER = 'https://example.brightspace.com';
    private const CLIENT_ID = 'client-1';

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
            ['deployment-1'],
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

    private function validator(int $clockSkewLeewaySeconds = 60): JwtValidator
    {
        $client = new Client();
        $factory = new HttpFactory();
        $jwksFetcher = new JwksFetcher($client, $factory, new ArrayCache());

        return new JwtValidator($jwksFetcher, new ArrayCache(), $clockSkewLeewaySeconds);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function issueToken(array $overrides = []): string
    {
        $now = time();
        $claims = array_merge([
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'exp' => $now + 300,
            'iat' => $now,
            'nonce' => bin2hex(random_bytes(8)),
        ], $overrides);

        return JWT::encode($claims, $this->platformKeyPair()->privateKey, 'RS256', $this->platformKeyPair()->kid);
    }

    private function assertRejectedWithReason(
        string $token,
        InvalidLaunchReason $reason,
        ?JwtValidator $validator = null,
    ): void {
        try {
            ($validator ?? $this->validator())->validate($token, $this->registration());
            self::fail('Expected InvalidLaunchException to be thrown.');
        } catch (InvalidLaunchException $exception) {
            self::assertSame($reason, $exception->reason);
        }
    }

    public function testValidatesARealSignedTokenAndReturnsClaims(): void
    {
        $this->publishJwks();
        $token = $this->issueToken(['sub' => 'user-1']);

        $claims = $this->validator()->validate($token, $this->registration());

        self::assertSame('user-1', $claims['sub']);
        self::assertSame(self::ISSUER, $claims['iss']);
    }

    public function testRejectsAnExpiredToken(): void
    {
        $this->publishJwks();
        $token = $this->issueToken(['exp' => time() - 1000, 'iat' => time() - 2000]);

        $this->assertRejectedWithReason($token, InvalidLaunchReason::Expired);
    }

    public function testRejectsATokenNotYetValid(): void
    {
        $this->publishJwks();
        $token = $this->issueToken(['iat' => time() + 1000]);

        $this->assertRejectedWithReason($token, InvalidLaunchReason::NotYetValid);
    }

    public function testRejectsATokenWithAKidNotPresentInTheJwks(): void
    {
        $this->publishJwks();
        $token = $this->issueToken();
        // Swap the header's kid for one that isn't in the published JWKS at all,
        // re-signing with the same (correct) private key so only the kid lookup fails.
        [, $payloadSegment, ] = explode('.', $token);
        $headerJson = (string) json_encode(['typ' => 'JWT', 'alg' => 'RS256', 'kid' => 'unknown-kid']);
        $header = rtrim(strtr(base64_encode($headerJson), '+/', '-_'), '=');
        $signingInput = $header . '.' . $payloadSegment;
        $signature = '';
        openssl_sign($signingInput, $signature, $this->platformKeyPair()->privateKey, OPENSSL_ALGO_SHA256);
        $tamperedToken = $signingInput . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        $this->assertRejectedWithReason($tamperedToken, InvalidLaunchReason::MalformedToken);
    }

    public function testRejectsATokenSignedByADifferentKey(): void
    {
        $this->publishJwks();
        $impostorKeyPair = (new KeyPairGenerator())->generate('platform-kid');
        $token = JWT::encode(
            ['iss' => self::ISSUER, 'aud' => self::CLIENT_ID, 'exp' => time() + 300, 'iat' => time(), 'nonce' => 'n'],
            $impostorKeyPair->privateKey,
            'RS256',
            'platform-kid',
        );

        $this->assertRejectedWithReason($token, InvalidLaunchReason::InvalidSignature);
    }

    public function testRejectsATokenWithATamperedPayloadUnderTheOriginalSignature(): void
    {
        $this->publishJwks();
        $token = $this->issueToken(['sub' => 'user-1']);
        [$headerSegment, $payloadSegment, $signatureSegment] = explode('.', $token);

        $payload = json_decode(JWT::urlsafeB64Decode($payloadSegment), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $payload['sub'] = 'attacker-controlled-user';
        $tamperedPayloadJson = (string) json_encode($payload, JSON_THROW_ON_ERROR);
        $tamperedPayloadSegment = rtrim(strtr(base64_encode($tamperedPayloadJson), '+/', '-_'), '=');

        $tamperedToken = $headerSegment . '.' . $tamperedPayloadSegment . '.' . $signatureSegment;

        $this->assertRejectedWithReason($tamperedToken, InvalidLaunchReason::InvalidSignature);
    }

    public function testNeverLeaksVendorJwtExceptionTypesAcrossThePublicApi(): void
    {
        $this->publishJwks();
        $token = $this->issueToken(['exp' => time() - 1000, 'iat' => time() - 2000]);

        try {
            $this->validator()->validate($token, $this->registration());
            self::fail('Expected an exception to be thrown.');
        } catch (\Throwable $exception) {
            // The only type allowed to escape is ours — a raw vendor
            // exception here would mean this catch(\Throwable) caught
            // something other than InvalidLaunchException.
            self::assertInstanceOf(InvalidLaunchException::class, $exception);
            // ...but we should still be wrapping the real vendor exception
            // as $previous, not discarding it.
            self::assertInstanceOf(ExpiredException::class, $exception->getPrevious());
        }
    }

    public function testRejectsAnAlgNoneToken(): void
    {
        $this->publishJwks();

        $header = rtrim(strtr(base64_encode((string) json_encode(['typ' => 'JWT', 'alg' => 'none'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode((string) json_encode([
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'exp' => time() + 300,
            'iat' => time(),
            'nonce' => 'n',
        ])), '+/', '-_'), '=');
        $token = $header . '.' . $payload . '.';

        $this->assertRejectedWithReason($token, InvalidLaunchReason::UnsupportedAlgorithm);
    }

    public function testRejectsMismatchedIssuerIncludingTrailingSlash(): void
    {
        $this->publishJwks();
        $token = $this->issueToken(['iss' => self::ISSUER . '/']);

        $this->assertRejectedWithReason($token, InvalidLaunchReason::InvalidIssuer);
    }

    public function testAcceptsAStringAudienceMatchingClientId(): void
    {
        $this->publishJwks();
        $token = $this->issueToken(['aud' => self::CLIENT_ID]);

        $claims = $this->validator()->validate($token, $this->registration());

        self::assertSame(self::CLIENT_ID, $claims['aud']);
    }

    public function testRejectsAStringAudienceNotMatchingClientId(): void
    {
        $this->publishJwks();
        $token = $this->issueToken(['aud' => 'someone-else']);

        $this->assertRejectedWithReason($token, InvalidLaunchReason::InvalidAudience);
    }

    public function testAcceptsAnArrayAudienceWithMatchingAzp(): void
    {
        $this->publishJwks();
        $token = $this->issueToken(['aud' => [self::CLIENT_ID, 'other-client'], 'azp' => self::CLIENT_ID]);

        $claims = $this->validator()->validate($token, $this->registration());

        self::assertSame([self::CLIENT_ID, 'other-client'], $claims['aud']);
    }

    public function testRejectsAnArrayAudienceWithoutAzp(): void
    {
        $this->publishJwks();
        $token = $this->issueToken(['aud' => [self::CLIENT_ID, 'other-client']]);

        $this->assertRejectedWithReason($token, InvalidLaunchReason::InvalidAudience);
    }

    public function testRejectsAnArrayAudienceWithMismatchedAzp(): void
    {
        $this->publishJwks();
        $token = $this->issueToken(['aud' => [self::CLIENT_ID, 'other-client'], 'azp' => 'other-client']);

        $this->assertRejectedWithReason($token, InvalidLaunchReason::InvalidAudience);
    }

    public function testRejectsATokenMissingTheNonceClaim(): void
    {
        $this->publishJwks();
        $now = time();
        $token = JWT::encode(
            ['iss' => self::ISSUER, 'aud' => self::CLIENT_ID, 'exp' => $now + 300, 'iat' => $now],
            $this->platformKeyPair()->privateKey,
            'RS256',
            $this->platformKeyPair()->kid,
        );

        $this->assertRejectedWithReason($token, InvalidLaunchReason::MissingNonce);
    }

    public function testRejectsAReplayedNonce(): void
    {
        $this->publishJwks();
        $token = $this->issueToken(['nonce' => 'fixed-nonce']);
        $validator = $this->validator();

        $validator->validate($token, $this->registration());

        try {
            $validator->validate($token, $this->registration());
            self::fail('Expected InvalidLaunchException to be thrown.');
        } catch (InvalidLaunchException $exception) {
            self::assertSame(InvalidLaunchReason::ReplayedNonce, $exception->reason);
        }
    }

    public function testAppliesConfiguredClockSkewLeeway(): void
    {
        $this->publishJwks();
        $token = $this->issueToken(['exp' => time() - 10, 'iat' => time() - 20]);

        $claims = $this->validator(clockSkewLeewaySeconds: 30)->validate($token, $this->registration());

        self::assertSame(self::ISSUER, $claims['iss']);
    }

    public function testAcceptsATokenWellWithinTheLeewayWindow(): void
    {
        // A few seconds of margin either side of the boundary, since real
        // wall-clock time elapses between token creation and validation —
        // testing the exact instant is inherently racy, not meaningful.
        $this->publishJwks();
        $token = $this->issueToken(['exp' => time() - 25, 'iat' => time() - 60]);

        $claims = $this->validator(clockSkewLeewaySeconds: 30)->validate($token, $this->registration());

        self::assertSame(self::ISSUER, $claims['iss']);
    }

    public function testRejectsATokenClearlyBeyondTheLeewayWindow(): void
    {
        $this->publishJwks();
        $token = $this->issueToken(['exp' => time() - 35, 'iat' => time() - 60]);

        $validator = $this->validator(clockSkewLeewaySeconds: 30);
        $this->assertRejectedWithReason($token, InvalidLaunchReason::Expired, $validator);
    }

    public function testRestoresTheJwtLibraryStaticLeewayAfterASuccessfulValidation(): void
    {
        $this->publishJwks();
        $token = $this->issueToken();
        JWT::$leeway = 12345;

        $this->validator()->validate($token, $this->registration());

        self::assertSame(12345, JWT::$leeway);
    }

    public function testRestoresTheJwtLibraryStaticLeewayAfterAFailedValidation(): void
    {
        $this->publishJwks();
        $token = $this->issueToken(['exp' => time() - 1000, 'iat' => time() - 2000]);
        JWT::$leeway = 54321;

        try {
            $this->validator()->validate($token, $this->registration());
        } catch (InvalidLaunchException) {
            // expected
        }

        self::assertSame(54321, JWT::$leeway);
    }
}
