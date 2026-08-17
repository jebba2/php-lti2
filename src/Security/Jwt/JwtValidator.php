<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Security\Jwt;

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use PhpLti\Lti1p3\Cache\CacheKeyBuilder;
use PhpLti\Lti1p3\Exception\InvalidLaunchException;
use PhpLti\Lti1p3\Exception\InvalidLaunchReason;
use PhpLti\Lti1p3\Registration\Registration;
use Psr\SimpleCache\CacheInterface;

/**
 * Validates an inbound id_token against a Registration: header algorithm
 * allow-list, signature (via the platform's JWKS), iss/aud/azp, exp/iat
 * with configurable clock-skew leeway, and nonce replay protection.
 *
 * Deliberately out of scope here: verifying that the id_token's
 * target_link_uri/deployment_id match what was recorded at OIDC
 * login-initiation time, and the `state` parameter roundtrip — both are
 * launch-flow concerns handled by LaunchValidator, not properties of the
 * token itself.
 */
final class JwtValidator
{
    private const ALLOWED_ALGORITHM = 'RS256';
    private const DEFAULT_CLOCK_SKEW_LEEWAY_SECONDS = 60;
    private const DEFAULT_NONCE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly JwksFetcher $jwksFetcher,
        private readonly CacheInterface $nonceCache,
        private readonly int $clockSkewLeewaySeconds = self::DEFAULT_CLOCK_SKEW_LEEWAY_SECONDS,
        private readonly int $nonceTtlSeconds = self::DEFAULT_NONCE_TTL_SECONDS,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function validate(string $idToken, Registration $registration): array
    {
        $this->assertAllowedAlgorithm($this->decodeHeaderAlgorithm($idToken));

        $keySet = $this->jwksFetcher->fetch($registration->platformJwksUrl);
        $claims = $this->decodeToken($idToken, $keySet);

        $this->assertIssuer($claims, $registration);
        $this->assertAudience($claims, $registration);
        $this->assertNonceNotReplayed($claims);

        return $claims;
    }

    private function decodeHeaderAlgorithm(string $jwt): string
    {
        $segments = explode('.', $jwt);
        if (count($segments) !== 3) {
            throw new InvalidLaunchException(
                InvalidLaunchReason::MalformedToken,
                'The id_token does not have three segments.',
            );
        }

        try {
            $headerJson = JWT::urlsafeB64Decode($segments[0]);
            $header = json_decode($headerJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new InvalidLaunchException(
                InvalidLaunchReason::MalformedToken,
                'The id_token header could not be decoded.',
                $exception,
            );
        }

        if (!is_array($header) || !isset($header['alg']) || !is_string($header['alg'])) {
            throw new InvalidLaunchException(
                InvalidLaunchReason::MalformedToken,
                'The id_token header does not contain an "alg".',
            );
        }

        return $header['alg'];
    }

    private function assertAllowedAlgorithm(string $algorithm): void
    {
        if ($algorithm !== self::ALLOWED_ALGORITHM) {
            throw new InvalidLaunchException(
                InvalidLaunchReason::UnsupportedAlgorithm,
                sprintf('The id_token uses unsupported algorithm "%s".', $algorithm),
            );
        }
    }

    /**
     * @param array<string, \Firebase\JWT\Key> $keySet
     * @return array<string, mixed>
     */
    private function decodeToken(string $idToken, array $keySet): array
    {
        $previousLeeway = JWT::$leeway;
        JWT::$leeway = $this->clockSkewLeewaySeconds;

        try {
            $decoded = JWT::decode($idToken, $keySet);
        } catch (ExpiredException $exception) {
            throw new InvalidLaunchException(InvalidLaunchReason::Expired, 'The id_token has expired.', $exception);
        } catch (BeforeValidException $exception) {
            throw new InvalidLaunchException(
                InvalidLaunchReason::NotYetValid,
                'The id_token is not yet valid.',
                $exception,
            );
        } catch (SignatureInvalidException $exception) {
            throw new InvalidLaunchException(
                InvalidLaunchReason::InvalidSignature,
                'The id_token signature is invalid.',
                $exception,
            );
        } catch (\UnexpectedValueException $exception) {
            throw new InvalidLaunchException(
                InvalidLaunchReason::MalformedToken,
                'The id_token could not be verified: ' . $exception->getMessage(),
                $exception,
            );
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        /** @var array<string, mixed> $claims */
        $claims = json_decode((string) json_encode($decoded, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        return $claims;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function assertIssuer(array $claims, Registration $registration): void
    {
        $issuer = $claims['iss'] ?? null;

        if (!is_string($issuer) || $issuer !== $registration->issuer) {
            throw new InvalidLaunchException(
                InvalidLaunchReason::InvalidIssuer,
                'The id_token "iss" does not match the registered issuer.',
            );
        }
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function assertAudience(array $claims, Registration $registration): void
    {
        $audience = $claims['aud'] ?? null;

        if (is_string($audience)) {
            if ($audience !== $registration->clientId) {
                throw new InvalidLaunchException(
                    InvalidLaunchReason::InvalidAudience,
                    'The id_token "aud" does not match the registered client_id.',
                );
            }

            return;
        }

        if (is_array($audience)) {
            if (!in_array($registration->clientId, $audience, true)) {
                throw new InvalidLaunchException(
                    InvalidLaunchReason::InvalidAudience,
                    'The id_token "aud" array does not contain the registered client_id.',
                );
            }

            $authorizedParty = $claims['azp'] ?? null;
            if ($authorizedParty !== $registration->clientId) {
                throw new InvalidLaunchException(
                    InvalidLaunchReason::InvalidAudience,
                    'The id_token "aud" is an array but "azp" does not match the registered client_id.',
                );
            }

            return;
        }

        throw new InvalidLaunchException(
            InvalidLaunchReason::InvalidAudience,
            'The id_token "aud" claim is missing or has an unexpected type.',
        );
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function assertNonceNotReplayed(array $claims): void
    {
        $nonce = $claims['nonce'] ?? null;

        if (!is_string($nonce) || $nonce === '') {
            throw new InvalidLaunchException(
                InvalidLaunchReason::MissingNonce,
                'The id_token does not contain a "nonce" claim.',
            );
        }

        $cacheKey = CacheKeyBuilder::build('nonce', $nonce);

        if ($this->nonceCache->has($cacheKey)) {
            throw new InvalidLaunchException(
                InvalidLaunchReason::ReplayedNonce,
                'The id_token "nonce" has already been used.',
            );
        }

        $this->nonceCache->set($cacheKey, true, $this->nonceTtlSeconds);
    }
}
