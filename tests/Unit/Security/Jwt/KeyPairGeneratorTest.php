<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Unit\Security\Jwt;

use PhpLti\Lti1p3\Security\Jwt\KeyPairGenerator;
use PHPUnit\Framework\TestCase;

final class KeyPairGeneratorTest extends TestCase
{
    public function testGeneratesAToolKeyPairWithTheGivenKid(): void
    {
        $keyPair = (new KeyPairGenerator())->generate('kid-1');

        self::assertSame('kid-1', $keyPair->kid);
        self::assertTrue($keyPair->activeForSigning);
        self::assertStringContainsString('BEGIN PRIVATE KEY', $keyPair->privateKey);
        self::assertStringContainsString('BEGIN PUBLIC KEY', $keyPair->publicKey);
    }

    public function testGeneratesRealUsableRsaKeys(): void
    {
        $keyPair = (new KeyPairGenerator())->generate('kid-1');

        $privateKeyResource = openssl_pkey_get_private($keyPair->privateKey);
        self::assertNotFalse($privateKeyResource, 'Generated private key must be parseable by openssl.');

        $publicKeyResource = openssl_pkey_get_public($keyPair->publicKey);
        self::assertNotFalse($publicKeyResource, 'Generated public key must be parseable by openssl.');

        $message = 'sign and verify round trip';
        $signed = openssl_sign($message, $signature, $privateKeyResource, OPENSSL_ALGO_SHA256);
        self::assertTrue($signed);

        self::assertSame(1, openssl_verify($message, $signature, $publicKeyResource, OPENSSL_ALGO_SHA256));
    }

    public function testDefaultKeySizeIsAtLeast2048Bits(): void
    {
        $keyPair = (new KeyPairGenerator())->generate('kid-1');

        $publicKeyResource = openssl_pkey_get_public($keyPair->publicKey);
        self::assertNotFalse($publicKeyResource);

        $details = openssl_pkey_get_details($publicKeyResource);
        self::assertIsArray($details);
        self::assertGreaterThanOrEqual(2048, $details['bits']);
    }

    public function testEachGeneratedKeyPairIsUnique(): void
    {
        $generator = new KeyPairGenerator();

        $first = $generator->generate('kid-1');
        $second = $generator->generate('kid-2');

        self::assertNotSame($first->privateKey, $second->privateKey);
    }
}
