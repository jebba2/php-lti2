<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Message\Claims;

/**
 * The `launch_presentation` claim: how the platform is displaying the tool
 * (iframe/window/embed, dimensions, a return URL, locale). All optional.
 */
final class LaunchPresentation
{
    private function __construct(
        public readonly ?string $documentTarget,
        public readonly ?int $height,
        public readonly ?int $width,
        public readonly ?string $returnUrl,
        public readonly ?string $locale,
    ) {
    }

    public static function fromClaimValue(mixed $value): ?self
    {
        if (!is_array($value)) {
            return null;
        }

        return new self(
            ClaimAccessor::optionalString($value, 'document_target'),
            ClaimAccessor::optionalInt($value, 'height'),
            ClaimAccessor::optionalInt($value, 'width'),
            ClaimAccessor::optionalString($value, 'return_url'),
            ClaimAccessor::optionalString($value, 'locale'),
        );
    }
}
