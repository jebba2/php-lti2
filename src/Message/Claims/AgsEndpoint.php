<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Message\Claims;

/**
 * The `endpoint` claim (Assignment and Grades Service), naming the line
 * items collection URL, the single line item URL when the launch is
 * associated with exactly one, and the granted AGS scopes. Can appear on
 * any authorized LTI message, not just a resource link launch.
 */
final class AgsEndpoint
{
    /**
     * @param list<string> $scopes
     */
    private function __construct(
        public readonly array $scopes,
        public readonly string $lineItemsUrl,
        public readonly ?string $lineItemUrl,
    ) {
    }

    public static function fromClaimValue(mixed $value): ?self
    {
        if (!is_array($value)) {
            return null;
        }

        $lineItemsUrl = ClaimAccessor::optionalString($value, 'lineitems');
        if ($lineItemsUrl === null) {
            return null;
        }

        return new self(
            ClaimAccessor::optionalStringList($value, 'scope'),
            $lineItemsUrl,
            ClaimAccessor::optionalString($value, 'lineitem'),
        );
    }
}
