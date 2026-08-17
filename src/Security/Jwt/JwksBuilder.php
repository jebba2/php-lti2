<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Security\Jwt;

use PhpLti\Lti1p3\Exception\InvalidRegistrationException;
use PhpLti\Lti1p3\Registration\Registration;
use PhpLti\Lti1p3\Registration\ToolKeyPair;

/**
 * Builds the tool's own public JWKS document (RFC 7517) from a
 * Registration's key set, for publishing at the tool's JWKS URL. Includes
 * every key, not just the active signing one, so the platform can keep
 * verifying tokens signed with a key that has since been rotated out.
 */
final class JwksBuilder
{
    /**
     * @return array{keys: list<array{kty: string, use: string, alg: string, kid: string, n: string, e: string}>}
     */
    public function build(Registration $registration): array
    {
        $keys = [];
        foreach ($registration->toolKeyPairs as $keyPair) {
            $keys[] = $this->buildJwk($keyPair);
        }

        return ['keys' => $keys];
    }

    /**
     * Builds a single JWK entry for one key pair, e.g. for a tool setup
     * script that needs to print just the newly generated key.
     *
     * @return array{kty: string, use: string, alg: string, kid: string, n: string, e: string}
     */
    public function buildJwk(ToolKeyPair $keyPair): array
    {
        $publicKeyResource = openssl_pkey_get_public($keyPair->publicKey);
        if ($publicKeyResource === false) {
            throw new InvalidRegistrationException(sprintf(
                'Could not parse public key for kid "%s".',
                $keyPair->kid,
            ));
        }

        $details = openssl_pkey_get_details($publicKeyResource);
        if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new InvalidRegistrationException(sprintf(
                'Public key for kid "%s" is not a valid RSA key.',
                $keyPair->kid,
            ));
        }

        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $keyPair->kid,
            'n' => $this->base64UrlEncode($details['rsa']['n']),
            'e' => $this->base64UrlEncode($details['rsa']['e']),
        ];
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
