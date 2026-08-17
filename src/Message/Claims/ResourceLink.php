<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Message\Claims;

use PhpLti\Lti1p3\Exception\InvalidLaunchException;
use PhpLti\Lti1p3\Exception\InvalidLaunchReason;

/**
 * The `resource_link` claim, required on a resource link launch.
 */
final class ResourceLink
{
    private function __construct(
        public readonly string $id,
        public readonly ?string $title,
        public readonly ?string $description,
    ) {
    }

    public static function fromClaimValue(mixed $value, string $context): self
    {
        if (!is_array($value)) {
            throw new InvalidLaunchException(
                InvalidLaunchReason::MissingRequiredClaim,
                sprintf('%s is missing the "resource_link" claim.', $context),
            );
        }

        return new self(
            ClaimAccessor::requireString($value, 'id', $context . ' resource_link'),
            ClaimAccessor::optionalString($value, 'title'),
            ClaimAccessor::optionalString($value, 'description'),
        );
    }
}
