<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Unit\Message\DeepLinking;

use PhpLti\Lti1p3\Message\ClaimUris;
use PhpLti\Lti1p3\Message\DeepLinking\LtiDeepLinkingRequest;
use PhpLti\Lti1p3\Message\LtiMessage;
use PHPUnit\Framework\TestCase;

final class LtiDeepLinkingRequestTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function baseClaims(array $overrides = []): array
    {
        return array_merge([
            ClaimUris::MESSAGE_TYPE => 'LtiDeepLinkingRequest',
            ClaimUris::VERSION => '1.3.0',
            ClaimUris::DEPLOYMENT_ID => 'deployment-1',
            ClaimUris::TARGET_LINK_URI => 'https://tool.example.com/deep-link',
            ClaimUris::DEEP_LINKING_SETTINGS => [
                'deep_link_return_url' => 'https://example.com/deep-link-return',
                'accept_types' => ['ltiResourceLink'],
            ],
        ], $overrides);
    }

    public function testIsAnLtiMessage(): void
    {
        $request = LtiDeepLinkingRequest::fromClaims($this->baseClaims());

        self::assertInstanceOf(LtiMessage::class, $request);
    }

    public function testParsesDeepLinkingSettings(): void
    {
        $request = LtiDeepLinkingRequest::fromClaims($this->baseClaims());

        self::assertSame('https://example.com/deep-link-return', $request->deepLinkingSettings->deepLinkReturnUrl);
        self::assertSame(['ltiResourceLink'], $request->deepLinkingSettings->acceptTypes);
    }

    public function testInheritsCommonLtiMessageProperties(): void
    {
        $request = LtiDeepLinkingRequest::fromClaims($this->baseClaims());

        self::assertSame('deployment-1', $request->deploymentId);
        self::assertSame('https://tool.example.com/deep-link', $request->targetLinkUri);
    }
}
