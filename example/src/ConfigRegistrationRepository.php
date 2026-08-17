<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3Example;

use PhpLti\Lti1p3\Registration\Registration;
use PhpLti\Lti1p3\Registration\RegistrationRepositoryInterface;

/**
 * The simplest possible RegistrationRepositoryInterface: this example only
 * ever talks to one platform (the bundled simulator, or whichever real LMS
 * you've pointed config/config.php at), so there's exactly one Registration
 * to match against.
 */
final class ConfigRegistrationRepository implements RegistrationRepositoryInterface
{
    public function __construct(private readonly Registration $registration)
    {
    }

    public function findForLoginInitiation(string $issuer, ?string $clientId): ?Registration
    {
        if ($issuer !== $this->registration->issuer) {
            return null;
        }

        if ($clientId !== null && $clientId !== $this->registration->clientId) {
            return null;
        }

        return $this->registration;
    }

    public function findForLaunch(string $issuer, string $clientId, string $deploymentId): ?Registration
    {
        if ($issuer !== $this->registration->issuer || $clientId !== $this->registration->clientId) {
            return null;
        }

        return $this->registration->hasDeployment($deploymentId) ? $this->registration : null;
    }
}
