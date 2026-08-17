<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Message\DeepLinking\ContentItem;

/**
 * A Deep Linking content item pointing to a downloadable file, optionally
 * with a short-lived URL that expires.
 */
final class FileContentItem implements ContentItem
{
    public function __construct(
        public readonly string $url,
        public readonly ?string $title = null,
        public readonly ?string $text = null,
        public readonly ?\DateTimeImmutable $expiresAt = null,
    ) {
    }

    public function toArray(): array
    {
        $data = ['type' => 'file', 'url' => $this->url];

        if ($this->title !== null) {
            $data['title'] = $this->title;
        }

        if ($this->text !== null) {
            $data['text'] = $this->text;
        }

        if ($this->expiresAt !== null) {
            $data['expiresAt'] = $this->expiresAt->format(\DateTimeInterface::ATOM);
        }

        return $data;
    }
}
