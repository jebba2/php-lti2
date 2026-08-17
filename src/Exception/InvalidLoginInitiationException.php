<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Exception;

/**
 * Thrown when an OIDC third-party login initiation request is missing a
 * required parameter (iss, login_hint, target_link_uri) or has one of an
 * unexpected type.
 */
class InvalidLoginInitiationException extends LtiException
{
}
