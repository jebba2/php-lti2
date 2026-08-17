<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Message\Claims;

use PhpLti\Lti1p3\Exception\InvalidLaunchException;
use PhpLti\Lti1p3\Exception\InvalidLaunchReason;

/**
 * The `deep_linking_settings` claim, required on a Deep Linking request:
 * where to send the response, what content types/targets the platform
 * accepts, and optional opaque `data` the tool must echo back unchanged.
 */
final class DeepLinkingSettings
{
    /**
     * @param list<string> $acceptTypes
     * @param list<string> $acceptPresentationDocumentTargets
     */
    private function __construct(
        public readonly string $deepLinkReturnUrl,
        public readonly array $acceptTypes,
        public readonly array $acceptPresentationDocumentTargets,
        public readonly ?bool $acceptMultiple,
        public readonly ?bool $acceptLineItem,
        public readonly ?bool $autoCreate,
        public readonly ?string $title,
        public readonly ?string $text,
        public readonly ?string $data,
    ) {
    }

    public static function fromClaimValue(mixed $value, string $context): self
    {
        if (!is_array($value)) {
            throw new InvalidLaunchException(
                InvalidLaunchReason::MissingRequiredClaim,
                sprintf('%s is missing the "deep_linking_settings" claim.', $context),
            );
        }

        return new self(
            ClaimAccessor::requireString($value, 'deep_link_return_url', $context . ' deep_linking_settings'),
            ClaimAccessor::optionalStringList($value, 'accept_types'),
            ClaimAccessor::optionalStringList($value, 'accept_presentation_document_targets'),
            ClaimAccessor::optionalBool($value, 'accept_multiple'),
            ClaimAccessor::optionalBool($value, 'accept_lineitem'),
            ClaimAccessor::optionalBool($value, 'auto_create'),
            ClaimAccessor::optionalString($value, 'title'),
            ClaimAccessor::optionalString($value, 'text'),
            ClaimAccessor::optionalString($value, 'data'),
        );
    }
}
