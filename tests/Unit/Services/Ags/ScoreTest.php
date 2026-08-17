<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Unit\Services\Ags;

use PhpLti\Lti1p3\Services\Ags\ActivityProgress;
use PhpLti\Lti1p3\Services\Ags\GradingProgress;
use PhpLti\Lti1p3\Services\Ags\Score;
use PHPUnit\Framework\TestCase;

final class ScoreTest extends TestCase
{
    public function testToArrayIncludesRequiredFieldsAndDefaultsTimestampToNow(): void
    {
        $score = new Score('user-1', ActivityProgress::Completed, GradingProgress::FullyGraded);

        $data = $score->toArray();

        self::assertSame('user-1', $data['userId']);
        self::assertSame('Completed', $data['activityProgress']);
        self::assertSame('FullyGraded', $data['gradingProgress']);
        self::assertArrayHasKey('timestamp', $data);
        self::assertArrayNotHasKey('scoreGiven', $data);
        self::assertArrayNotHasKey('scoreMaximum', $data);
        self::assertArrayNotHasKey('comment', $data);
    }

    public function testToArrayIncludesOptionalFieldsWhenSet(): void
    {
        $timestamp = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $score = new Score(
            'user-1',
            ActivityProgress::Completed,
            GradingProgress::FullyGraded,
            scoreGiven: 8.5,
            scoreMaximum: 10.0,
            comment: 'Nice work',
            timestamp: $timestamp,
        );

        $data = $score->toArray();

        self::assertSame(8.5, $data['scoreGiven']);
        self::assertSame(10.0, $data['scoreMaximum']);
        self::assertSame('Nice work', $data['comment']);
        self::assertSame($timestamp->format(\DateTimeInterface::ATOM), $data['timestamp']);
    }

    public function testProgressOnlyUpdateOmitsScoreGiven(): void
    {
        $score = new Score('user-1', ActivityProgress::InProgress, GradingProgress::Pending);

        self::assertArrayNotHasKey('scoreGiven', $score->toArray());
    }
}
