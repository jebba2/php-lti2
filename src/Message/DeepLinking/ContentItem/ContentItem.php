<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Message\DeepLinking\ContentItem;

/**
 * A single item in an LtiDeepLinkingResponse's `content_items` claim.
 */
interface ContentItem
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
