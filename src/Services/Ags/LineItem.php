<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Services\Ags;

use PhpLti\Lti1p3\Exception\ServiceException;

/**
 * An Assignment and Grades Service line item (gradebook column). `id` is
 * null for a line item you're about to create — the platform assigns and
 * returns its URL once created.
 */
final class LineItem
{
    public function __construct(
        public readonly ?string $id,
        public readonly float $scoreMaximum,
        public readonly string $label,
        public readonly ?string $resourceId,
        public readonly ?string $tag,
        public readonly ?string $resourceLinkId,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'scoreMaximum' => $this->scoreMaximum,
            'label' => $this->label,
        ];

        if ($this->resourceId !== null) {
            $data['resourceId'] = $this->resourceId;
        }

        if ($this->tag !== null) {
            $data['tag'] = $this->tag;
        }

        if ($this->resourceLinkId !== null) {
            $data['resourceLinkId'] = $this->resourceLinkId;
        }

        if ($this->id !== null) {
            $data['id'] = $this->id;
        }

        return $data;
    }

    public static function fromResponseData(mixed $data): self
    {
        if (!is_array($data)) {
            throw new ServiceException('AGS line item response was not a JSON object.');
        }

        $scoreMaximum = $data['scoreMaximum'] ?? null;
        if (!is_int($scoreMaximum) && !is_float($scoreMaximum)) {
            throw new ServiceException('AGS line item response is missing a numeric "scoreMaximum".');
        }

        $label = $data['label'] ?? null;
        if (!is_string($label) || $label === '') {
            throw new ServiceException('AGS line item response is missing a "label".');
        }

        return new self(
            is_string($data['id'] ?? null) ? $data['id'] : null,
            (float) $scoreMaximum,
            $label,
            is_string($data['resourceId'] ?? null) ? $data['resourceId'] : null,
            is_string($data['tag'] ?? null) ? $data['tag'] : null,
            is_string($data['resourceLinkId'] ?? null) ? $data['resourceLinkId'] : null,
        );
    }
}
