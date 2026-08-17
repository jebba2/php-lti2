<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Unit\Message\DeepLinking\ContentItem;

use PhpLti\Lti1p3\Message\DeepLinking\ContentItem\ContentItemLineItem;
use PhpLti\Lti1p3\Message\DeepLinking\ContentItem\FileContentItem;
use PhpLti\Lti1p3\Message\DeepLinking\ContentItem\HtmlContentItem;
use PhpLti\Lti1p3\Message\DeepLinking\ContentItem\ImageContentItem;
use PhpLti\Lti1p3\Message\DeepLinking\ContentItem\LinkContentItem;
use PhpLti\Lti1p3\Message\DeepLinking\ContentItem\LtiResourceLinkContentItem;
use PHPUnit\Framework\TestCase;

final class ContentItemsTest extends TestCase
{
    public function testLtiResourceLinkContentItemMinimal(): void
    {
        $item = new LtiResourceLinkContentItem();

        self::assertSame(['type' => 'ltiResourceLink'], $item->toArray());
    }

    public function testLtiResourceLinkContentItemWithAllFields(): void
    {
        $item = new LtiResourceLinkContentItem(
            url: 'https://tool.example.com/launch/1',
            title: 'A resource',
            text: 'Description',
            custom: ['foo' => 'bar'],
            lineItem: new ContentItemLineItem(100.0, label: 'Assignment 1'),
        );

        self::assertSame([
            'type' => 'ltiResourceLink',
            'url' => 'https://tool.example.com/launch/1',
            'title' => 'A resource',
            'text' => 'Description',
            'custom' => ['foo' => 'bar'],
            'lineItem' => ['scoreMaximum' => 100.0, 'label' => 'Assignment 1'],
        ], $item->toArray());
    }

    public function testContentItemLineItemMinimal(): void
    {
        $lineItem = new ContentItemLineItem(100.0);

        self::assertSame(['scoreMaximum' => 100.0], $lineItem->toArray());
    }

    public function testContentItemLineItemWithAllFields(): void
    {
        $lineItem = new ContentItemLineItem(100.0, label: 'A', resourceId: 'res-1', tag: 'tag-1', gradesReleased: true);

        self::assertSame([
            'scoreMaximum' => 100.0,
            'label' => 'A',
            'resourceId' => 'res-1',
            'tag' => 'tag-1',
            'gradesReleased' => true,
        ], $lineItem->toArray());
    }

    public function testLinkContentItem(): void
    {
        $item = new LinkContentItem('https://example.com', title: 'A link');

        self::assertSame(['type' => 'link', 'url' => 'https://example.com', 'title' => 'A link'], $item->toArray());
    }

    public function testFileContentItem(): void
    {
        $expiresAt = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $item = new FileContentItem('https://example.com/file.pdf', expiresAt: $expiresAt);

        $data = $item->toArray();
        self::assertSame('file', $data['type']);
        self::assertSame('https://example.com/file.pdf', $data['url']);
        self::assertSame($expiresAt->format(\DateTimeInterface::ATOM), $data['expiresAt']);
    }

    public function testHtmlContentItem(): void
    {
        $item = new HtmlContentItem('<p>Hello</p>', title: 'Greeting');

        self::assertSame(['type' => 'html', 'html' => '<p>Hello</p>', 'title' => 'Greeting'], $item->toArray());
    }

    public function testImageContentItem(): void
    {
        $item = new ImageContentItem('https://example.com/image.png', width: 100, height: 200);

        self::assertSame([
            'type' => 'image',
            'url' => 'https://example.com/image.png',
            'width' => 100,
            'height' => 200,
        ], $item->toArray());
    }
}
