<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Unit\Message\Claims;

use PhpLti\Lti1p3\Message\Claims\AgsEndpoint;
use PHPUnit\Framework\TestCase;

final class AgsEndpointTest extends TestCase
{
    public function testParsesScopesLineItemsUrlAndLineItemUrl(): void
    {
        $endpoint = AgsEndpoint::fromClaimValue([
            'scope' => [
                'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem',
                'https://purl.imsglobal.org/spec/lti-ags/scope/score',
            ],
            'lineitems' => 'https://example.com/ags/lineitems',
            'lineitem' => 'https://example.com/ags/lineitems/1',
        ]);

        self::assertNotNull($endpoint);
        self::assertSame(
            [
                'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem',
                'https://purl.imsglobal.org/spec/lti-ags/scope/score',
            ],
            $endpoint->scopes,
        );
        self::assertSame('https://example.com/ags/lineitems', $endpoint->lineItemsUrl);
        self::assertSame('https://example.com/ags/lineitems/1', $endpoint->lineItemUrl);
    }

    public function testLineItemUrlIsNullWhenNotPresent(): void
    {
        $endpoint = AgsEndpoint::fromClaimValue([
            'scope' => [],
            'lineitems' => 'https://example.com/ags/lineitems',
        ]);

        self::assertNotNull($endpoint);
        self::assertNull($endpoint->lineItemUrl);
    }

    public function testReturnsNullWhenClaimIsMissingOrWrongType(): void
    {
        self::assertNull(AgsEndpoint::fromClaimValue(null));
        self::assertNull(AgsEndpoint::fromClaimValue('not-an-array'));
    }

    public function testReturnsNullWhenLineItemsUrlIsMissing(): void
    {
        self::assertNull(AgsEndpoint::fromClaimValue(['scope' => []]));
    }
}
