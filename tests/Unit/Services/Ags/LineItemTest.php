<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Unit\Services\Ags;

use PhpLti\Lti1p3\Exception\ServiceException;
use PhpLti\Lti1p3\Services\Ags\LineItem;
use PHPUnit\Framework\TestCase;

final class LineItemTest extends TestCase
{
    public function testToArrayOmitsNullFieldsAndIdWhenNotSet(): void
    {
        $lineItem = new LineItem(null, 100.0, 'Assignment 1', null, null, null);

        self::assertSame(['scoreMaximum' => 100.0, 'label' => 'Assignment 1'], $lineItem->toArray());
    }

    public function testToArrayIncludesOptionalFieldsWhenSet(): void
    {
        $lineItem = new LineItem('https://example.com/lineitems/1', 100.0, 'Assignment 1', 'res-1', 'tag-1', 'link-1');

        self::assertSame([
            'scoreMaximum' => 100.0,
            'label' => 'Assignment 1',
            'resourceId' => 'res-1',
            'tag' => 'tag-1',
            'resourceLinkId' => 'link-1',
            'id' => 'https://example.com/lineitems/1',
        ], $lineItem->toArray());
    }

    public function testFromResponseDataParsesAllFields(): void
    {
        $lineItem = LineItem::fromResponseData([
            'id' => 'https://example.com/lineitems/1',
            'scoreMaximum' => 100,
            'label' => 'Assignment 1',
            'resourceId' => 'res-1',
            'tag' => 'tag-1',
            'resourceLinkId' => 'link-1',
        ]);

        self::assertSame('https://example.com/lineitems/1', $lineItem->id);
        self::assertSame(100.0, $lineItem->scoreMaximum);
        self::assertSame('Assignment 1', $lineItem->label);
        self::assertSame('res-1', $lineItem->resourceId);
        self::assertSame('tag-1', $lineItem->tag);
        self::assertSame('link-1', $lineItem->resourceLinkId);
    }

    public function testFromResponseDataAllowsOptionalFieldsToBeAbsent(): void
    {
        $lineItem = LineItem::fromResponseData(['scoreMaximum' => 50, 'label' => 'Quiz']);

        self::assertNull($lineItem->id);
        self::assertNull($lineItem->resourceId);
        self::assertNull($lineItem->tag);
        self::assertNull($lineItem->resourceLinkId);
    }

    public function testFromResponseDataThrowsWhenNotAnArray(): void
    {
        $this->expectException(ServiceException::class);

        LineItem::fromResponseData('not-an-array');
    }

    public function testFromResponseDataThrowsWhenScoreMaximumIsMissing(): void
    {
        $this->expectException(ServiceException::class);

        LineItem::fromResponseData(['label' => 'Assignment 1']);
    }

    public function testFromResponseDataThrowsWhenLabelIsMissing(): void
    {
        $this->expectException(ServiceException::class);

        LineItem::fromResponseData(['scoreMaximum' => 100]);
    }
}
