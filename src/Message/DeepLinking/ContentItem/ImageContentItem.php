<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Message\DeepLinking\ContentItem;

/**
 * A Deep Linking content item pointing to an image.
 */
final class ImageContentItem implements ContentItem
{
    public function __construct(
        public readonly string $url,
        public readonly ?string $title = null,
        public readonly ?string $text = null,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
    ) {
    }

    public function toArray(): array
    {
        $data = ['type' => 'image', 'url' => $this->url];

        if ($this->title !== null) {
            $data['title'] = $this->title;
        }

        if ($this->text !== null) {
            $data['text'] = $this->text;
        }

        if ($this->width !== null) {
            $data['width'] = $this->width;
        }

        if ($this->height !== null) {
            $data['height'] = $this->height;
        }

        return $data;
    }
}
