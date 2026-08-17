<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Exception;

/**
 * Thrown when an outbound call to a platform service (JWKS, token exchange,
 * AGS, NRPS) fails or returns something we can't make sense of.
 */
class ServiceException extends LtiException
{
}
