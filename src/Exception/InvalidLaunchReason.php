<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Exception;

/**
 * Why an inbound launch (id_token) failed validation. Carried on
 * InvalidLaunchException so callers can distinguish failure modes
 * (e.g. for logging/telemetry) without a combinatorial exception
 * class hierarchy.
 */
enum InvalidLaunchReason
{
    case UnsupportedAlgorithm;
    case MalformedToken;
    case InvalidSignature;
    case Expired;
    case NotYetValid;
    case InvalidIssuer;
    case InvalidAudience;
    case MissingNonce;
    case ReplayedNonce;
    case InvalidState;
    case InvalidDeploymentId;
    case MissingRequiredClaim;
    case UnexpectedMessageType;
    case UnsupportedVersion;
}
