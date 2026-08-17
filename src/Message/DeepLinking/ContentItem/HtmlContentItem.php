<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Message\DeepLinking\ContentItem;

/**
 * A Deep Linking content item embedding a raw HTML fragment.
 */
final class HtmlContentItem implements ContentItem
{
    public function __construct(
        public readonly string $html,
        public readonly ?string $title = null,
        public readonly ?string $text = null,
    ) {
    }

    public function toArray(): array
    {
        $data = ['type' => 'html', 'html' => $this->html];

        if ($this->title !== null) {
            $data['title'] = $this->title;
        }

        if ($this->text !== null) {
            $data['text'] = $this->text;
        }

        return $data;
    }
}
