<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Unit\Message\Claims;

use PhpLti\Lti1p3\Exception\InvalidLaunchException;
use PhpLti\Lti1p3\Exception\InvalidLaunchReason;
use PhpLti\Lti1p3\Message\Claims\ClaimAccessor;
use PHPUnit\Framework\TestCase;

final class ClaimAccessorTest extends TestCase
{
    public function testRequireStringReturnsThePresentValue(): void
    {
        self::assertSame('value', ClaimAccessor::requireString(['key' => 'value'], 'key', 'context'));
    }

    public function testRequireStringThrowsWhenMissing(): void
    {
        try {
            ClaimAccessor::requireString([], 'key', 'context');
            self::fail('Expected InvalidLaunchException.');
        } catch (InvalidLaunchException $exception) {
            self::assertSame(InvalidLaunchReason::MissingRequiredClaim, $exception->reason);
        }
    }

    public function testRequireStringThrowsWhenEmpty(): void
    {
        $this->expectException(InvalidLaunchException::class);

        ClaimAccessor::requireString(['key' => ''], 'key', 'context');
    }

    public function testRequireStringThrowsWhenWrongType(): void
    {
        $this->expectException(InvalidLaunchException::class);

        ClaimAccessor::requireString(['key' => 123], 'key', 'context');
    }

    public function testOptionalStringReturnsNullWhenMissing(): void
    {
        self::assertNull(ClaimAccessor::optionalString([], 'key'));
    }

    public function testOptionalStringReturnsNullWhenEmpty(): void
    {
        self::assertNull(ClaimAccessor::optionalString(['key' => ''], 'key'));
    }

    public function testOptionalStringReturnsNullWhenWrongType(): void
    {
        self::assertNull(ClaimAccessor::optionalString(['key' => 123], 'key'));
    }

    public function testOptionalStringReturnsTheValueWhenPresent(): void
    {
        self::assertSame('value', ClaimAccessor::optionalString(['key' => 'value'], 'key'));
    }

    public function testOptionalIntReturnsTheIntWhenPresent(): void
    {
        self::assertSame(42, ClaimAccessor::optionalInt(['key' => 42], 'key'));
    }

    public function testOptionalBoolReturnsTheBoolWhenPresent(): void
    {
        self::assertTrue(ClaimAccessor::optionalBool(['key' => true], 'key'));
        self::assertFalse(ClaimAccessor::optionalBool(['key' => false], 'key'));
    }

    public function testOptionalBoolReturnsNullWhenMissingOrWrongType(): void
    {
        self::assertNull(ClaimAccessor::optionalBool([], 'key'));
        self::assertNull(ClaimAccessor::optionalBool(['key' => 'true'], 'key'));
    }

    public function testOptionalIntReturnsNullWhenMissingOrWrongType(): void
    {
        self::assertNull(ClaimAccessor::optionalInt([], 'key'));
        self::assertNull(ClaimAccessor::optionalInt(['key' => 'not-an-int'], 'key'));
        self::assertNull(ClaimAccessor::optionalInt(['key' => '42'], 'key'));
    }

    public function testOptionalStringListFiltersOutNonStringEntries(): void
    {
        self::assertSame(['a', 'b'], ClaimAccessor::optionalStringList(['key' => ['a', 'b', 123, null]], 'key'));
    }

    public function testOptionalStringListReturnsEmptyArrayWhenMissingOrWrongType(): void
    {
        self::assertSame([], ClaimAccessor::optionalStringList([], 'key'));
        self::assertSame([], ClaimAccessor::optionalStringList(['key' => 'not-an-array'], 'key'));
    }

    public function testOptionalArrayReturnsTheArrayWhenPresent(): void
    {
        self::assertSame(['a' => 1], ClaimAccessor::optionalArray(['key' => ['a' => 1]], 'key'));
    }

    public function testOptionalArrayReturnsNullWhenMissingOrWrongType(): void
    {
        self::assertNull(ClaimAccessor::optionalArray([], 'key'));
        self::assertNull(ClaimAccessor::optionalArray(['key' => 'not-an-array'], 'key'));
    }
}
