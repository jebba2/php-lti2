<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Unit\Security\Jwt;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use PhpLti\Lti1p3\Exception\InvalidRegistrationException;
use PhpLti\Lti1p3\Registration\Registration;
use PhpLti\Lti1p3\Registration\ToolKeyPair;
use PhpLti\Lti1p3\Security\Jwt\JwksBuilder;
use PhpLti\Lti1p3\Security\Jwt\KeyPairGenerator;
use PHPUnit\Framework\TestCase;

final class JwksBuilderTest extends TestCase
{
    private function registrationWithKeyPairs(ToolKeyPair ...$toolKeyPairs): Registration
    {
        return new Registration(
            'https://example.brightspace.com',
            'client-1',
            ['deployment-1'],
            'https://example.brightspace.com/d2l/lti/authenticate',
            'https://example.brightspace.com/core/connect/token',
            'https://example.brightspace.com/d2l/.well-known/jwks',
            array_values($toolKeyPairs),
        );
    }

    public function testBuildsJwksDocumentWithOneEntryPerKey(): void
    {
        $keyPair = (new KeyPairGenerator())->generate('kid-1');
        $registration = $this->registrationWithKeyPairs($keyPair);

        $jwks = (new JwksBuilder())->build($registration);

        self::assertArrayHasKey('keys', $jwks);
        self::assertCount(1, $jwks['keys']);
        self::assertSame('RSA', $jwks['keys'][0]['kty']);
        self::assertSame('sig', $jwks['keys'][0]['use']);
        self::assertSame('RS256', $jwks['keys'][0]['alg']);
        self::assertSame('kid-1', $jwks['keys'][0]['kid']);
        self::assertNotSame('', $jwks['keys'][0]['n']);
        self::assertNotSame('', $jwks['keys'][0]['e']);
    }

    public function testBuildJwkReturnsASingleJwkEntryForOneKeyPair(): void
    {
        $keyPair = (new KeyPairGenerator())->generate('kid-1');

        $jwk = (new JwksBuilder())->buildJwk($keyPair);

        self::assertSame('RSA', $jwk['kty']);
        self::assertSame('sig', $jwk['use']);
        self::assertSame('RS256', $jwk['alg']);
        self::assertSame('kid-1', $jwk['kid']);
        self::assertNotSame('', $jwk['n']);
        self::assertNotSame('', $jwk['e']);
    }

    public function testIncludesRotatedOutInactiveKeysSoPlatformCanStillVerifyThem(): void
    {
        $generator = new KeyPairGenerator();
        $rotatedOutKey = $generator->generate('old-kid');
        $oldInactiveKey = new ToolKeyPair(
            $rotatedOutKey->kid,
            $rotatedOutKey->privateKey,
            $rotatedOutKey->publicKey,
            activeForSigning: false,
        );
        $newActiveKey = $generator->generate('new-kid');
        $registration = $this->registrationWithKeyPairs($oldInactiveKey, $newActiveKey);

        $jwks = (new JwksBuilder())->build($registration);

        $kids = array_map(static fn (array $key): string => $key['kid'], $jwks['keys']);
        self::assertSame(['old-kid', 'new-kid'], $kids);
    }

    public function testJwkEncodingInteroperatesWithARealIndependentJwtLibrary(): void
    {
        $keyPair = (new KeyPairGenerator())->generate('kid-1');
        $registration = $this->registrationWithKeyPairs($keyPair);

        $jwks = (new JwksBuilder())->build($registration);
        $keySet = JWK::parseKeySet($jwks, 'RS256');

        $token = JWT::encode(['sub' => 'user-1'], $keyPair->privateKey, 'RS256', $keyPair->kid);
        $decoded = JWT::decode($token, $keySet);

        self::assertSame('user-1', $decoded->sub);
    }

    public function testThrowsWhenPublicKeyIsNotValidPem(): void
    {
        $invalidKeyPair = new ToolKeyPair('kid-1', 'not-a-real-private-key', 'not-a-real-public-key');
        $registration = $this->registrationWithKeyPairs($invalidKeyPair);

        $this->expectException(InvalidRegistrationException::class);
        $this->expectExceptionMessage('Could not parse public key for kid "kid-1"');

        (new JwksBuilder())->build($registration);
    }
}
