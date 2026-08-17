<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Unit\Services\Ags;

use PhpLti\Lti1p3\Exception\ServiceException;
use PhpLti\Lti1p3\Services\Ags\Result;
use PHPUnit\Framework\TestCase;

final class ResultTest extends TestCase
{
    public function testParsesAllFields(): void
    {
        $result = Result::fromResponseData([
            'id' => 'https://example.com/results/1',
            'scoreOf' => 'https://example.com/lineitems/1',
            'userId' => 'user-1',
            'resultScore' => 8.5,
            'resultMaximum' => 10,
            'comment' => 'Nice work',
        ]);

        self::assertSame('https://example.com/results/1', $result->id);
        self::assertSame('https://example.com/lineitems/1', $result->scoreOf);
        self::assertSame('user-1', $result->userId);
        self::assertSame(8.5, $result->resultScore);
        self::assertSame(10.0, $result->resultMaximum);
        self::assertSame('Nice work', $result->comment);
    }

    public function testOptionalFieldsAreNullWhenAbsent(): void
    {
        $result = Result::fromResponseData([
            'id' => 'https://example.com/results/1',
            'scoreOf' => 'https://example.com/lineitems/1',
            'userId' => 'user-1',
        ]);

        self::assertNull($result->resultScore);
        self::assertNull($result->resultMaximum);
        self::assertNull($result->comment);
    }

    public function testThrowsWhenNotAnArray(): void
    {
        $this->expectException(ServiceException::class);

        Result::fromResponseData('not-an-array');
    }

    public function testThrowsWhenARequiredFieldIsMissing(): void
    {
        $this->expectException(ServiceException::class);

        Result::fromResponseData(['id' => 'x', 'scoreOf' => 'y']);
    }
}
