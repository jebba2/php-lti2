<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Services\Ags;

/**
 * A score publish payload (POST .../lineitems/{id}/scores).
 */
final class Score
{
    public function __construct(
        public readonly string $userId,
        public readonly ActivityProgress $activityProgress,
        public readonly GradingProgress $gradingProgress,
        public readonly ?float $scoreGiven = null,
        public readonly ?float $scoreMaximum = null,
        public readonly ?string $comment = null,
        public readonly ?\DateTimeImmutable $timestamp = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'userId' => $this->userId,
            'activityProgress' => $this->activityProgress->value,
            'gradingProgress' => $this->gradingProgress->value,
            'timestamp' => ($this->timestamp ?? new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        if ($this->scoreGiven !== null) {
            $data['scoreGiven'] = $this->scoreGiven;
        }

        if ($this->scoreMaximum !== null) {
            $data['scoreMaximum'] = $this->scoreMaximum;
        }

        if ($this->comment !== null) {
            $data['comment'] = $this->comment;
        }

        return $data;
    }
}
