<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Unit\Message\Claims;

use PhpLti\Lti1p3\Exception\InvalidLaunchException;
use PhpLti\Lti1p3\Message\Claims\DeepLinkingSettings;
use PHPUnit\Framework\TestCase;

final class DeepLinkingSettingsTest extends TestCase
{
    public function testParsesAllFields(): void
    {
        $settings = DeepLinkingSettings::fromClaimValue([
            'deep_link_return_url' => 'https://example.com/deep-link-return',
            'accept_types' => ['ltiResourceLink', 'link'],
            'accept_presentation_document_targets' => ['iframe', 'window'],
            'accept_multiple' => false,
            'accept_lineitem' => true,
            'auto_create' => true,
            'title' => 'Select content',
            'text' => 'Choose an item',
            'data' => 'opaque-data-value',
        ], 'context');

        self::assertSame('https://example.com/deep-link-return', $settings->deepLinkReturnUrl);
        self::assertSame(['ltiResourceLink', 'link'], $settings->acceptTypes);
        self::assertSame(['iframe', 'window'], $settings->acceptPresentationDocumentTargets);
        self::assertFalse($settings->acceptMultiple);
        self::assertTrue($settings->acceptLineItem);
        self::assertTrue($settings->autoCreate);
        self::assertSame('Select content', $settings->title);
        self::assertSame('Choose an item', $settings->text);
        self::assertSame('opaque-data-value', $settings->data);
    }

    public function testOptionalFieldsAreNullWhenAbsent(): void
    {
        $settings = DeepLinkingSettings::fromClaimValue([
            'deep_link_return_url' => 'https://example.com/deep-link-return',
        ], 'context');

        self::assertSame([], $settings->acceptTypes);
        self::assertSame([], $settings->acceptPresentationDocumentTargets);
        self::assertNull($settings->acceptMultiple);
        self::assertNull($settings->acceptLineItem);
        self::assertNull($settings->autoCreate);
        self::assertNull($settings->title);
        self::assertNull($settings->text);
        self::assertNull($settings->data);
    }

    public function testThrowsWhenClaimIsMissing(): void
    {
        $this->expectException(InvalidLaunchException::class);

        DeepLinkingSettings::fromClaimValue(null, 'context');
    }

    public function testThrowsWhenDeepLinkReturnUrlIsMissing(): void
    {
        $this->expectException(InvalidLaunchException::class);

        DeepLinkingSettings::fromClaimValue(['accept_types' => []], 'context');
    }
}
