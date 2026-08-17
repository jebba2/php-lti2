<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Unit\Cache;

use PhpLti\Lti1p3\Cache\CacheKeyBuilder;
use PHPUnit\Framework\TestCase;

final class CacheKeyBuilderTest extends TestCase
{
    public function testBuildsAConsistentKeyForTheSameInputs(): void
    {
        $first = CacheKeyBuilder::build('jwks', 'https://example.brightspace.com/d2l/.well-known/jwks');
        $second = CacheKeyBuilder::build('jwks', 'https://example.brightspace.com/d2l/.well-known/jwks');

        self::assertSame($first, $second);
    }

    public function testDifferentPartsProduceDifferentKeys(): void
    {
        $first = CacheKeyBuilder::build('jwks', 'https://one.brightspace.com/jwks');
        $second = CacheKeyBuilder::build('jwks', 'https://two.brightspace.com/jwks');

        self::assertNotSame($first, $second);
    }

    public function testOrderOfPartsMatters(): void
    {
        $first = CacheKeyBuilder::build('token', 'issuer-a', 'client-b');
        $second = CacheKeyBuilder::build('token', 'client-b', 'issuer-a');

        self::assertNotSame($first, $second);
    }

    public function testDifferentNamespacesProduceDifferentKeysForTheSameParts(): void
    {
        $first = CacheKeyBuilder::build('jwks', 'shared-part');
        $second = CacheKeyBuilder::build('nonce', 'shared-part');

        self::assertNotSame($first, $second);
    }

    /**
     * PSR-16 (SimpleCache) forbids these characters in cache keys. Real
     * inputs (issuer URLs, JWKS URLs) routinely contain '://' and ':'.
     */
    public function testKeyContainsNoCharactersForbiddenByPsr16(): void
    {
        $key = CacheKeyBuilder::build(
            'jwks',
            'https://example.brightspace.com:443/d2l/.well-known/jwks',
            'client@example.com',
        );

        foreach (str_split('{}()/\@:') as $forbiddenCharacter) {
            self::assertStringNotContainsString($forbiddenCharacter, $key);
        }
    }

    public function testKeyIncludesASanitizedNamespaceForReadability(): void
    {
        $key = CacheKeyBuilder::build('jwks', 'anything');

        self::assertStringStartsWith('lti1p3_jwks_', $key);
    }

    public function testNamespaceIsSanitizedWhenItContainsForbiddenCharacters(): void
    {
        $key = CacheKeyBuilder::build('jwks:platform', 'anything');

        foreach (str_split('{}()/\@:') as $forbiddenCharacter) {
            self::assertStringNotContainsString($forbiddenCharacter, $key);
        }
    }
}
