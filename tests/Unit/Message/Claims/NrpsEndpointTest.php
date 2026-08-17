<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Unit\Message\Claims;

use PhpLti\Lti1p3\Message\Claims\NrpsEndpoint;
use PHPUnit\Framework\TestCase;

final class NrpsEndpointTest extends TestCase
{
    public function testParsesContextMembershipsUrlAndServiceVersions(): void
    {
        $endpoint = NrpsEndpoint::fromClaimValue([
            'context_memberships_url' => 'https://example.com/nrps/context/1/memberships',
            'service_versions' => ['2.0'],
        ]);

        self::assertNotNull($endpoint);
        self::assertSame('https://example.com/nrps/context/1/memberships', $endpoint->contextMembershipsUrl);
        self::assertSame(['2.0'], $endpoint->serviceVersions);
    }

    public function testReturnsNullWhenClaimIsMissingOrWrongType(): void
    {
        self::assertNull(NrpsEndpoint::fromClaimValue(null));
        self::assertNull(NrpsEndpoint::fromClaimValue('not-an-array'));
    }

    public function testReturnsNullWhenContextMembershipsUrlIsMissing(): void
    {
        self::assertNull(NrpsEndpoint::fromClaimValue(['service_versions' => ['2.0']]));
    }
}
