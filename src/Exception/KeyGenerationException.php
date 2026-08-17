<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Exception;

/**
 * Thrown when generating or reading an RSA key pair fails at the openssl layer.
 */
class KeyGenerationException extends LtiException
{
}
