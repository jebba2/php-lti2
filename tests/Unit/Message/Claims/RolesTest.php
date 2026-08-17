<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Unit\Message\Claims;

use PhpLti\Lti1p3\Message\Claims\Roles;
use PHPUnit\Framework\TestCase;

final class RolesTest extends TestCase
{
    public function testIsInstructorReturnsTrueWhenTheRoleUriIsPresent(): void
    {
        $roles = Roles::fromClaimValue([Roles::INSTRUCTOR]);

        self::assertTrue($roles->isInstructor());
        self::assertFalse($roles->isLearner());
    }

    public function testIsLearnerReturnsTrueWhenTheRoleUriIsPresent(): void
    {
        $roles = Roles::fromClaimValue([Roles::LEARNER]);

        self::assertTrue($roles->isLearner());
        self::assertFalse($roles->isInstructor());
    }

    public function testHasReturnsTrueForAnyRoleUriPresent(): void
    {
        $roles = Roles::fromClaimValue(['https://example.com/some-custom-institution-role']);

        self::assertTrue($roles->has('https://example.com/some-custom-institution-role'));
        self::assertFalse($roles->has('https://example.com/other-role'));
    }

    public function testAllReturnsEveryRoleUriExactlyAsReceived(): void
    {
        $roles = Roles::fromClaimValue([Roles::INSTRUCTOR, 'https://example.com/custom-role']);

        self::assertSame([Roles::INSTRUCTOR, 'https://example.com/custom-role'], $roles->all());
    }

    public function testUnrecognizedRoleUrisDoNotCauseAnError(): void
    {
        $roles = Roles::fromClaimValue(['https://example.com/a-role-this-library-has-never-heard-of']);

        self::assertFalse($roles->isInstructor());
        self::assertSame(['https://example.com/a-role-this-library-has-never-heard-of'], $roles->all());
    }

    public function testFromClaimValueDefaultsToEmptyWhenMissingOrWrongType(): void
    {
        self::assertSame([], Roles::fromClaimValue(null)->all());
        self::assertSame([], Roles::fromClaimValue('not-an-array')->all());
    }

    public function testFromClaimValueFiltersOutNonStringEntries(): void
    {
        $roles = Roles::fromClaimValue([Roles::INSTRUCTOR, 123, null]);

        self::assertSame([Roles::INSTRUCTOR], $roles->all());
    }
}
