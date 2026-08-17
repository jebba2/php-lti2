<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Message\Claims;

/**
 * The `namesroleservice` claim (Names and Role Provisioning Service),
 * naming the context memberships URL and supported service versions. Can
 * appear on any authorized LTI message, not just a resource link launch.
 */
final class NrpsEndpoint
{
    /**
     * @param list<string> $serviceVersions
     */
    private function __construct(
        public readonly string $contextMembershipsUrl,
        public readonly array $serviceVersions,
    ) {
    }

    public static function fromClaimValue(mixed $value): ?self
    {
        if (!is_array($value)) {
            return null;
        }

        $contextMembershipsUrl = ClaimAccessor::optionalString($value, 'context_memberships_url');
        if ($contextMembershipsUrl === null) {
            return null;
        }

        return new self(
            $contextMembershipsUrl,
            ClaimAccessor::optionalStringList($value, 'service_versions'),
        );
    }
}
