<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Registration;

use PhpLti\Lti1p3\Exception\InvalidRegistrationException;

/**
 * One of the tool's RSA signing keys. A Registration holds a list of these
 * to support key rotation: an old key stays published in the tool's JWKS
 * (so the platform can still verify tokens signed before rotation) even
 * after a new key becomes activeForSigning.
 */
final class ToolKeyPair
{
    public readonly string $kid;
    public readonly string $privateKey;
    public readonly string $publicKey;
    public readonly bool $activeForSigning;

    public function __construct(string $kid, string $privateKey, string $publicKey, bool $activeForSigning = true)
    {
        if (trim($kid) === '') {
            throw new InvalidRegistrationException('ToolKeyPair kid must not be empty.');
        }

        if (trim($privateKey) === '') {
            throw new InvalidRegistrationException('ToolKeyPair privateKey must not be empty.');
        }

        if (trim($publicKey) === '') {
            throw new InvalidRegistrationException('ToolKeyPair publicKey must not be empty.');
        }

        $this->kid = $kid;
        $this->privateKey = $privateKey;
        $this->publicKey = $publicKey;
        $this->activeForSigning = $activeForSigning;
    }
}
