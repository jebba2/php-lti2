<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Security\Jwt;

use PhpLti\Lti1p3\Exception\KeyGenerationException;
use PhpLti\Lti1p3\Registration\ToolKeyPair;

/**
 * Generates RSA key pairs for tool signing keys. Backs bin/generate-keypair.php;
 * kept as a class (rather than logic inline in the script) so it can be
 * exercised directly by tests and reused wherever a fresh key is needed
 * (e.g. building fixture "tool" keys in integration tests).
 */
final class KeyPairGenerator
{
    public function __construct(private readonly int $keyBits = 2048)
    {
    }

    public function generate(string $kid): ToolKeyPair
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => $this->keyBits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($resource === false) {
            throw new KeyGenerationException(
                'Failed to generate RSA key pair: ' . $this->lastOpensslError(),
            );
        }

        if (!openssl_pkey_export($resource, $privateKeyPem)) {
            throw new KeyGenerationException(
                'Failed to export RSA private key: ' . $this->lastOpensslError(),
            );
        }

        $details = openssl_pkey_get_details($resource);
        if ($details === false || !isset($details['key'])) {
            throw new KeyGenerationException(
                'Failed to read RSA public key details: ' . $this->lastOpensslError(),
            );
        }

        return new ToolKeyPair($kid, $privateKeyPem, $details['key']);
    }

    private function lastOpensslError(): string
    {
        $message = openssl_error_string();

        return $message === false ? 'unknown openssl error' : $message;
    }
}
