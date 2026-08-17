<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Message\DeepLinking\ContentItem;

/**
 * The optional `lineItem` sub-object of an `ltiResourceLink` content item:
 * asks the platform to create a gradebook column alongside the link.
 */
final class ContentItemLineItem
{
    public function __construct(
        public readonly float $scoreMaximum,
        public readonly ?string $label = null,
        public readonly ?string $resourceId = null,
        public readonly ?string $tag = null,
        public readonly ?bool $gradesReleased = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = ['scoreMaximum' => $this->scoreMaximum];

        if ($this->label !== null) {
            $data['label'] = $this->label;
        }

        if ($this->resourceId !== null) {
            $data['resourceId'] = $this->resourceId;
        }

        if ($this->tag !== null) {
            $data['tag'] = $this->tag;
        }

        if ($this->gradesReleased !== null) {
            $data['gradesReleased'] = $this->gradesReleased;
        }

        return $data;
    }
}
