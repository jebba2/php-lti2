<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Unit\Registration;

use PhpLti\Lti1p3\Exception\InvalidRegistrationException;
use PhpLti\Lti1p3\Registration\ToolKeyPair;
use PHPUnit\Framework\TestCase;

final class ToolKeyPairTest extends TestCase
{
    public function testConstructsWithValidValues(): void
    {
        $keyPair = new ToolKeyPair(
            kid: 'kid-1',
            privateKey: "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----",
            publicKey: "-----BEGIN PUBLIC KEY-----\ndef\n-----END PUBLIC KEY-----",
            activeForSigning: true,
        );

        self::assertSame('kid-1', $keyPair->kid);
        self::assertStringContainsString('BEGIN PRIVATE KEY', $keyPair->privateKey);
        self::assertStringContainsString('BEGIN PUBLIC KEY', $keyPair->publicKey);
        self::assertTrue($keyPair->activeForSigning);
    }

    public function testActiveForSigningDefaultsToTrue(): void
    {
        $keyPair = new ToolKeyPair('kid-1', 'priv', 'pub');

        self::assertTrue($keyPair->activeForSigning);
    }

    public function testCanBeMarkedInactiveForSigning(): void
    {
        $keyPair = new ToolKeyPair('kid-1', 'priv', 'pub', activeForSigning: false);

        self::assertFalse($keyPair->activeForSigning);
    }

    public function testRejectsEmptyKid(): void
    {
        $this->expectException(InvalidRegistrationException::class);
        $this->expectExceptionMessage('kid must not be empty');

        new ToolKeyPair('', 'priv', 'pub');
    }

    public function testRejectsBlankKid(): void
    {
        $this->expectException(InvalidRegistrationException::class);

        new ToolKeyPair('   ', 'priv', 'pub');
    }

    public function testRejectsEmptyPrivateKey(): void
    {
        $this->expectException(InvalidRegistrationException::class);
        $this->expectExceptionMessage('privateKey must not be empty');

        new ToolKeyPair('kid-1', '', 'pub');
    }

    public function testRejectsEmptyPublicKey(): void
    {
        $this->expectException(InvalidRegistrationException::class);
        $this->expectExceptionMessage('publicKey must not be empty');

        new ToolKeyPair('kid-1', 'priv', '');
    }
}
