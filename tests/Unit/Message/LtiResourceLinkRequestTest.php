<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Unit\Message;

use PhpLti\Lti1p3\Message\ClaimUris;
use PhpLti\Lti1p3\Message\LtiResourceLinkRequest;
use PHPUnit\Framework\TestCase;

final class LtiResourceLinkRequestTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function baseClaims(array $overrides = []): array
    {
        return array_merge([
            ClaimUris::MESSAGE_TYPE => 'LtiResourceLinkRequest',
            ClaimUris::VERSION => '1.3.0',
            ClaimUris::DEPLOYMENT_ID => 'deployment-1',
            ClaimUris::TARGET_LINK_URI => 'https://tool.example.com/launch',
            ClaimUris::RESOURCE_LINK => ['id' => 'resource-1'],
        ], $overrides);
    }

    public function testAgsEndpointReturnsNullWhenClaimIsAbsent(): void
    {
        $request = LtiResourceLinkRequest::fromClaims($this->baseClaims());

        self::assertNull($request->agsEndpoint());
    }

    public function testAgsEndpointParsesTheEndpointClaimWhenPresent(): void
    {
        $request = LtiResourceLinkRequest::fromClaims($this->baseClaims([
            ClaimUris::AGS_ENDPOINT => [
                'scope' => ['https://purl.imsglobal.org/spec/lti-ags/scope/lineitem'],
                'lineitems' => 'https://example.com/ags/lineitems',
                'lineitem' => 'https://example.com/ags/lineitems/1',
            ],
        ]));

        $endpoint = $request->agsEndpoint();

        self::assertNotNull($endpoint);
        self::assertSame('https://example.com/ags/lineitems', $endpoint->lineItemsUrl);
        self::assertSame('https://example.com/ags/lineitems/1', $endpoint->lineItemUrl);
    }

    public function testNrpsEndpointReturnsNullWhenClaimIsAbsent(): void
    {
        $request = LtiResourceLinkRequest::fromClaims($this->baseClaims());

        self::assertNull($request->nrpsEndpoint());
    }

    public function testNrpsEndpointParsesTheEndpointClaimWhenPresent(): void
    {
        $request = LtiResourceLinkRequest::fromClaims($this->baseClaims([
            ClaimUris::NRPS_ENDPOINT => [
                'context_memberships_url' => 'https://example.com/nrps/context/1/memberships',
                'service_versions' => ['2.0'],
            ],
        ]));

        $endpoint = $request->nrpsEndpoint();

        self::assertNotNull($endpoint);
        self::assertSame('https://example.com/nrps/context/1/memberships', $endpoint->contextMembershipsUrl);
    }
}
