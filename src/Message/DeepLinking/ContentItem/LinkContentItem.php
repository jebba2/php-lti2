<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Message\DeepLinking\ContentItem;

/**
 * A Deep Linking content item pointing to an arbitrary external URL.
 */
final class LinkContentItem implements ContentItem
{
    public function __construct(
        public readonly string $url,
        public readonly ?string $title = null,
        public readonly ?string $text = null,
    ) {
    }

    public function toArray(): array
    {
        $data = ['type' => 'link', 'url' => $this->url];

        if ($this->title !== null) {
            $data['title'] = $this->title;
        }

        if ($this->text !== null) {
            $data['text'] = $this->text;
        }

        return $data;
    }
}
