<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Exception;

/**
 * Thrown when no Registration can be found for the issuer (and, where
 * relevant, client_id/deployment_id) presented in an incoming request.
 */
class RegistrationNotFoundException extends LtiException
{
}
