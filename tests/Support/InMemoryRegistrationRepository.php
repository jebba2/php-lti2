<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Support;

use PhpLti\Lti1p3\Registration\Registration;
use PhpLti\Lti1p3\Registration\RegistrationRepositoryInterface;

/**
 * A real, in-memory implementation of RegistrationRepositoryInterface for
 * tests — not a mock of the interface, a genuine (if simple) implementation
 * of its contract, the same shape a host application's database-backed
 * implementation would follow.
 */
final class InMemoryRegistrationRepository implements RegistrationRepositoryInterface
{
    /** @var list<Registration> */
    private array $registrations = [];

    public function add(Registration $registration): void
    {
        $this->registrations[] = $registration;
    }

    public function findForLoginInitiation(string $issuer, ?string $clientId): ?Registration
    {
        $matches = array_values(array_filter(
            $this->registrations,
            static fn (Registration $registration): bool => $registration->issuer === $issuer
                && ($clientId === null || $registration->clientId === $clientId),
        ));

        if ($clientId === null) {
            return count($matches) === 1 ? $matches[0] : null;
        }

        return $matches[0] ?? null;
    }

    public function findForLaunch(string $issuer, string $clientId, string $deploymentId): ?Registration
    {
        foreach ($this->registrations as $registration) {
            if (
                $registration->issuer === $issuer
                && $registration->clientId === $clientId
                && $registration->hasDeployment($deploymentId)
            ) {
                return $registration;
            }
        }

        return null;
    }
}
