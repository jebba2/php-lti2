<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Services\Ags;

use PhpLti\Lti1p3\Exception\ServiceException;

/**
 * A single entry from an AGS results read (GET .../lineitems/{id}/results).
 */
final class Result
{
    private function __construct(
        public readonly string $id,
        public readonly string $scoreOf,
        public readonly string $userId,
        public readonly ?float $resultScore,
        public readonly ?float $resultMaximum,
        public readonly ?string $comment,
    ) {
    }

    public static function fromResponseData(mixed $data): self
    {
        if (!is_array($data)) {
            throw new ServiceException('AGS result response entry was not a JSON object.');
        }

        $id = $data['id'] ?? null;
        $scoreOf = $data['scoreOf'] ?? null;
        $userId = $data['userId'] ?? null;

        if (!is_string($id) || !is_string($scoreOf) || !is_string($userId)) {
            throw new ServiceException('AGS result response entry is missing required fields.');
        }

        $resultScore = $data['resultScore'] ?? null;
        $resultMaximum = $data['resultMaximum'] ?? null;

        return new self(
            $id,
            $scoreOf,
            $userId,
            is_int($resultScore) || is_float($resultScore) ? (float) $resultScore : null,
            is_int($resultMaximum) || is_float($resultMaximum) ? (float) $resultMaximum : null,
            is_string($data['comment'] ?? null) ? $data['comment'] : null,
        );
    }
}
