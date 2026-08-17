<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Unit\Message\Claims;

use PhpLti\Lti1p3\Exception\InvalidLaunchException;
use PhpLti\Lti1p3\Exception\InvalidLaunchReason;
use PhpLti\Lti1p3\Message\Claims\ResourceLink;
use PHPUnit\Framework\TestCase;

final class ResourceLinkTest extends TestCase
{
    public function testParsesIdTitleAndDescription(): void
    {
        $resourceLink = ResourceLink::fromClaimValue(
            ['id' => 'resource-1', 'title' => 'A resource', 'description' => 'A description'],
            'context',
        );

        self::assertSame('resource-1', $resourceLink->id);
        self::assertSame('A resource', $resourceLink->title);
        self::assertSame('A description', $resourceLink->description);
    }

    public function testTitleAndDescriptionAreNullWhenAbsent(): void
    {
        $resourceLink = ResourceLink::fromClaimValue(['id' => 'resource-1'], 'context');

        self::assertNull($resourceLink->title);
        self::assertNull($resourceLink->description);
    }

    public function testThrowsWhenTheClaimIsMissing(): void
    {
        try {
            ResourceLink::fromClaimValue(null, 'context');
            self::fail('Expected InvalidLaunchException.');
        } catch (InvalidLaunchException $exception) {
            self::assertSame(InvalidLaunchReason::MissingRequiredClaim, $exception->reason);
        }
    }

    public function testThrowsWhenIdIsMissing(): void
    {
        $this->expectException(InvalidLaunchException::class);

        ResourceLink::fromClaimValue(['title' => 'A resource'], 'context');
    }
}
