<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Registration;

/**
 * Implemented by the host application to look up its stored platform
 * registrations. This library ships no persistence of its own.
 */
interface RegistrationRepositoryInterface
{
    /**
     * Looks up a registration during OIDC third-party login initiation, when
     * the platform may or may not have supplied a client_id.
     *
     * Implementations must return null (never throw) when no registration
     * matches, and must also return null when $clientId is omitted and more
     * than one registration is registered for the given issuer (the request
     * is ambiguous and cannot be resolved safely).
     */
    public function findForLoginInitiation(string $issuer, ?string $clientId): ?Registration;

    /**
     * Looks up a registration to validate an inbound launch.
     *
     * Implementations must return null (never throw) when no registration
     * matches the given issuer + client_id, or when the matching
     * registration does not include $deploymentId among its deployment ids.
     */
    public function findForLaunch(string $issuer, string $clientId, string $deploymentId): ?Registration;
}
