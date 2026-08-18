<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Registration;

use PhpLti\Lti1p3\Exception\InvalidRegistrationException;
use PhpLti\Lti1p3\Security\UrlSecurity;

/**
 * A single tool registration against one platform (issuer + client_id),
 * covering the deployments, endpoints, and signing keys needed to validate
 * launches and call back into platform services.
 */
final class Registration
{
    public readonly string $issuer;
    public readonly string $clientId;

    /** @var list<string> */
    public readonly array $deploymentIds;

    public readonly string $platformAuthenticationLoginUrl;
    public readonly string $platformAuthenticationTokenUrl;
    public readonly string $platformJwksUrl;

    /**
     * Audience ("aud") to use in the client-credentials client assertion sent
     * to the platform's token endpoint. Null means "use
     * $platformAuthenticationTokenUrl", which is what the 1EdTech Security
     * Framework specifies and what most platforms expect; set it only for
     * platforms that require a different, fixed audience value.
     */
    public readonly ?string $platformAudience;

    /** @var list<ToolKeyPair> */
    public readonly array $toolKeyPairs;

    /**
     * @param list<string> $deploymentIds
     * @param list<ToolKeyPair> $toolKeyPairs
     */
    public function __construct(
        string $issuer,
        string $clientId,
        array $deploymentIds,
        string $platformAuthenticationLoginUrl,
        string $platformAuthenticationTokenUrl,
        string $platformJwksUrl,
        array $toolKeyPairs,
        ?string $platformAudience = null,
    ) {
        if (trim($issuer) === '') {
            throw new InvalidRegistrationException('Registration issuer must not be empty.');
        }

        if (trim($clientId) === '') {
            throw new InvalidRegistrationException('Registration clientId must not be empty.');
        }

        if ($deploymentIds === []) {
            throw new InvalidRegistrationException('Registration must have at least one deployment id.');
        }

        foreach ($deploymentIds as $deploymentId) {
            if (trim($deploymentId) === '') {
                throw new InvalidRegistrationException('Registration deployment ids must be non-empty strings.');
            }
        }

        if (trim($platformAuthenticationLoginUrl) === '') {
            throw new InvalidRegistrationException('Registration platformAuthenticationLoginUrl must not be empty.');
        }

        if (!UrlSecurity::isSecure($platformAuthenticationLoginUrl)) {
            throw new InvalidRegistrationException(
                'Registration platformAuthenticationLoginUrl must use https (or loopback for local dev/testing).',
            );
        }

        if (trim($platformAuthenticationTokenUrl) === '') {
            throw new InvalidRegistrationException('Registration platformAuthenticationTokenUrl must not be empty.');
        }

        if (!UrlSecurity::isSecure($platformAuthenticationTokenUrl)) {
            throw new InvalidRegistrationException(
                'Registration platformAuthenticationTokenUrl must use https (or loopback for local dev/testing).',
            );
        }

        if (trim($platformJwksUrl) === '') {
            throw new InvalidRegistrationException('Registration platformJwksUrl must not be empty.');
        }

        if (!UrlSecurity::isSecure($platformJwksUrl)) {
            throw new InvalidRegistrationException(
                'Registration platformJwksUrl must use https (or loopback for local dev/testing).',
            );
        }

        if ($platformAudience !== null && trim($platformAudience) === '') {
            throw new InvalidRegistrationException(
                'Registration platformAudience must not be empty when provided (pass null to use the token url).',
            );
        }

        if ($toolKeyPairs === []) {
            throw new InvalidRegistrationException('Registration must have at least one tool key pair.');
        }

        $activeSigningKeyCount = 0;
        foreach ($toolKeyPairs as $keyPair) {
            if ($keyPair->activeForSigning) {
                ++$activeSigningKeyCount;
            }
        }

        if ($activeSigningKeyCount !== 1) {
            throw new InvalidRegistrationException(sprintf(
                'Registration must have exactly one active signing key, %d given.',
                $activeSigningKeyCount,
            ));
        }

        $this->issuer = $issuer;
        $this->clientId = $clientId;
        $this->deploymentIds = array_values($deploymentIds);
        $this->platformAuthenticationLoginUrl = $platformAuthenticationLoginUrl;
        $this->platformAuthenticationTokenUrl = $platformAuthenticationTokenUrl;
        $this->platformJwksUrl = $platformJwksUrl;
        $this->platformAudience = $platformAudience;
        $this->toolKeyPairs = array_values($toolKeyPairs);
    }

    /**
     * The audience to put in the client assertion when requesting a service
     * access token: the configured override if there is one, otherwise the
     * platform's token endpoint (the spec default).
     */
    public function accessTokenAudience(): string
    {
        return $this->platformAudience ?? $this->platformAuthenticationTokenUrl;
    }

    public function hasDeployment(string $deploymentId): bool
    {
        return in_array($deploymentId, $this->deploymentIds, true);
    }

    public function activeSigningKey(): ToolKeyPair
    {
        foreach ($this->toolKeyPairs as $keyPair) {
            if ($keyPair->activeForSigning) {
                return $keyPair;
            }
        }

        // Unreachable: the constructor guarantees exactly one active signing key.
        throw new InvalidRegistrationException('No active signing key found.');
    }

    public function findKeyPairByKid(string $kid): ?ToolKeyPair
    {
        foreach ($this->toolKeyPairs as $keyPair) {
            if ($keyPair->kid === $kid) {
                return $keyPair;
            }
        }

        return null;
    }
}
