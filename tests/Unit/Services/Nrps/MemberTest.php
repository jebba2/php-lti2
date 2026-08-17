<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Unit\Services\Nrps;

use PhpLti\Lti1p3\Exception\ServiceException;
use PhpLti\Lti1p3\Services\Nrps\Member;
use PHPUnit\Framework\TestCase;

final class MemberTest extends TestCase
{
    public function testParsesAllFields(): void
    {
        $member = Member::fromResponseData([
            'status' => 'Active',
            'name' => 'Ada Lovelace',
            'user_id' => 'user-1',
            'roles' => ['http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor'],
            'email' => 'ada@example.com',
            'picture' => 'https://example.com/ada.jpg',
            'given_name' => 'Ada',
            'family_name' => 'Lovelace',
            'middle_name' => null,
        ]);

        self::assertSame('user-1', $member->userId);
        self::assertSame('Active', $member->status);
        self::assertSame('Ada Lovelace', $member->name);
        self::assertSame(['http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor'], $member->roles);
        self::assertSame('ada@example.com', $member->email);
        self::assertSame('https://example.com/ada.jpg', $member->picture);
        self::assertSame('Ada', $member->givenName);
        self::assertSame('Lovelace', $member->familyName);
        self::assertNull($member->middleName);
    }

    public function testStatusDefaultsToActiveWhenAbsent(): void
    {
        $member = Member::fromResponseData(['user_id' => 'user-1']);

        self::assertSame('Active', $member->status);
    }

    public function testRolesDefaultsToEmptyArrayWhenAbsent(): void
    {
        $member = Member::fromResponseData(['user_id' => 'user-1']);

        self::assertSame([], $member->roles);
    }

    public function testOptionalFieldsAreNullWhenAbsent(): void
    {
        $member = Member::fromResponseData(['user_id' => 'user-1']);

        self::assertNull($member->name);
        self::assertNull($member->email);
        self::assertNull($member->picture);
        self::assertNull($member->givenName);
        self::assertNull($member->familyName);
        self::assertNull($member->middleName);
    }

    public function testThrowsWhenNotAnArray(): void
    {
        $this->expectException(ServiceException::class);

        Member::fromResponseData('not-an-array');
    }

    public function testThrowsWhenUserIdIsMissing(): void
    {
        $this->expectException(ServiceException::class);

        Member::fromResponseData(['name' => 'Ada Lovelace']);
    }
}
