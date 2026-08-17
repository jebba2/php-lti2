<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Unit\Security;

use PhpLti\Lti1p3\Security\UrlSecurity;
use PHPUnit\Framework\TestCase;

final class UrlSecurityTest extends TestCase
{
    public function testHttpsUrlIsSecure(): void
    {
        self::assertTrue(UrlSecurity::isSecure('https://example.brightspace.com/d2l/.well-known/jwks'));
    }

    public function testPlainHttpUrlToARemoteHostIsNotSecure(): void
    {
        self::assertFalse(UrlSecurity::isSecure('http://example.brightspace.com/d2l/.well-known/jwks'));
    }

    public function testHttpToLoopbackAddressesIsAllowedForLocalDevAndTesting(): void
    {
        self::assertTrue(UrlSecurity::isSecure('http://127.0.0.1:8080/jwks'));
        self::assertTrue(UrlSecurity::isSecure('http://localhost:8080/jwks'));
        self::assertTrue(UrlSecurity::isSecure('http://[::1]:8080/jwks'));
    }

    public function testHttpsToLoopbackIsAlsoSecure(): void
    {
        self::assertTrue(UrlSecurity::isSecure('https://127.0.0.1:8443/jwks'));
    }

    public function testUnparseableOrSchemelessUrlIsNotSecure(): void
    {
        self::assertFalse(UrlSecurity::isSecure('not-a-url'));
        self::assertFalse(UrlSecurity::isSecure(''));
    }

    public function testOtherSchemesAreNotSecure(): void
    {
        self::assertFalse(UrlSecurity::isSecure('ftp://example.com/jwks'));
    }
}
