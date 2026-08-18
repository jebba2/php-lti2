<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Services;

use Firebase\JWT\JWT;
use PhpLti\Lti1p3\Cache\CacheKeyBuilder;
use PhpLti\Lti1p3\Exception\ServiceException;
use PhpLti\Lti1p3\Registration\Registration;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Obtains a platform access token via the OAuth2 client_credentials
 * JWT-bearer grant (1EdTech Security Framework 1.0), for calling AGS/NRPS
 * service endpoints. Tokens are cached per (issuer, client_id, scope set)
 * until shortly before they expire.
 */
final class AccessTokenService
{
    private const CLIENT_ASSERTION_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';
    private const CLIENT_ASSERTION_TTL_SECONDS = 60;
    private const DEFAULT_TOKEN_TTL_SECONDS = 3600;
    private const CACHE_SAFETY_MARGIN_SECONDS = 60;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @param list<string> $scopes
     */
    public function getAccessToken(Registration $registration, array $scopes): string
    {
        $cacheKey = CacheKeyBuilder::build(
            'access-token',
            $registration->issuer,
            $registration->clientId,
            implode(' ', $scopes),
        );

        $cached = $this->cache->get($cacheKey);
        if (is_string($cached)) {
            return $cached;
        }

        [$accessToken, $ttl] = $this->requestAccessToken($registration, $scopes);

        $this->cache->set($cacheKey, $accessToken, $ttl);

        return $accessToken;
    }

    /**
     * @param list<string> $scopes
     * @return array{0: string, 1: int}
     */
    private function requestAccessToken(Registration $registration, array $scopes): array
    {
        $body = http_build_query([
            'grant_type' => 'client_credentials',
            'client_assertion_type' => self::CLIENT_ASSERTION_TYPE,
            'client_assertion' => $this->buildClientAssertion($registration),
            'scope' => implode(' ', $scopes),
        ]);

        $request = $this->requestFactory
            ->createRequest('POST', $registration->platformAuthenticationTokenUrl)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streamFactory->createStream($body));

        $response = $this->httpClient->sendRequest($request);

        if ($response->getStatusCode() !== 200) {
            throw new ServiceException(sprintf(
                'Access token request to "%s" failed with HTTP status %d.',
                $registration->platformAuthenticationTokenUrl,
                $response->getStatusCode(),
            ));
        }

        try {
            $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ServiceException('Access token response was not valid JSON.', previous: $exception);
        }

        if (!is_array($decoded) || !isset($decoded['access_token']) || !is_string($decoded['access_token'])) {
            throw new ServiceException('Access token response did not contain an "access_token".');
        }

        $expiresIn = is_int($decoded['expires_in'] ?? null) ? $decoded['expires_in'] : self::DEFAULT_TOKEN_TTL_SECONDS;
        $ttl = max(0, $expiresIn - self::CACHE_SAFETY_MARGIN_SECONDS);

        return [$decoded['access_token'], $ttl];
    }

    private function buildClientAssertion(Registration $registration): string
    {
        $now = time();
        $claims = [
            'iss' => $registration->clientId,
            'sub' => $registration->clientId,
            'aud' => $registration->accessTokenAudience(),
            'iat' => $now,
            'exp' => $now + self::CLIENT_ASSERTION_TTL_SECONDS,
            'jti' => bin2hex(random_bytes(16)),
        ];

        $signingKey = $registration->activeSigningKey();

        return JWT::encode($claims, $signingKey->privateKey, 'RS256', $signingKey->kid);
    }
}
