<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Exception;

/**
 * Base type for every exception thrown by this library, so consumers can
 * catch a single type if they don't need to distinguish failure modes.
 */
class LtiException extends \RuntimeException
{
}
