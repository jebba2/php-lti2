<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Message\Claims;

/**
 * The `tool_platform` claim: information about the platform instance
 * making the launch (Brightspace's own GUID, name, version, etc.).
 */
final class ToolPlatform
{
    private function __construct(
        public readonly string $guid,
        public readonly ?string $name,
        public readonly ?string $contactEmail,
        public readonly ?string $description,
        public readonly ?string $url,
        public readonly ?string $productFamilyCode,
        public readonly ?string $version,
    ) {
    }

    public static function fromClaimValue(mixed $value): ?self
    {
        if (!is_array($value)) {
            return null;
        }

        $guid = ClaimAccessor::optionalString($value, 'guid');
        if ($guid === null) {
            return null;
        }

        return new self(
            $guid,
            ClaimAccessor::optionalString($value, 'name'),
            ClaimAccessor::optionalString($value, 'contact_email'),
            ClaimAccessor::optionalString($value, 'description'),
            ClaimAccessor::optionalString($value, 'url'),
            ClaimAccessor::optionalString($value, 'product_family_code'),
            ClaimAccessor::optionalString($value, 'version'),
        );
    }
}
