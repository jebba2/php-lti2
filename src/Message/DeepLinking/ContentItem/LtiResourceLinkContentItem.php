<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Message\DeepLinking\ContentItem;

/**
 * The most common Deep Linking content item type: a link back into this
 * tool, optionally requesting the platform create a line item alongside it.
 */
final class LtiResourceLinkContentItem implements ContentItem
{
    /**
     * @param array<string, string> $custom
     */
    public function __construct(
        public readonly ?string $url = null,
        public readonly ?string $title = null,
        public readonly ?string $text = null,
        public readonly array $custom = [],
        public readonly ?ContentItemLineItem $lineItem = null,
    ) {
    }

    public function toArray(): array
    {
        $data = ['type' => 'ltiResourceLink'];

        if ($this->url !== null) {
            $data['url'] = $this->url;
        }

        if ($this->title !== null) {
            $data['title'] = $this->title;
        }

        if ($this->text !== null) {
            $data['text'] = $this->text;
        }

        if ($this->custom !== []) {
            $data['custom'] = $this->custom;
        }

        if ($this->lineItem !== null) {
            $data['lineItem'] = $this->lineItem->toArray();
        }

        return $data;
    }
}
