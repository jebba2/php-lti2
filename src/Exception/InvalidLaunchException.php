<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Exception;

/**
 * Thrown when an inbound launch (id_token) fails validation. Vendor
 * exceptions from the underlying JWT library are never allowed to cross
 * this library's public API boundary — JwtValidator catches and remaps
 * them here instead.
 */
final class InvalidLaunchException extends LtiException
{
    public function __construct(
        public readonly InvalidLaunchReason $reason,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
