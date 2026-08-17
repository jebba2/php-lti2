<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Security\Jwt;

use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use PhpLti\Lti1p3\Cache\CacheKeyBuilder;
use PhpLti\Lti1p3\Exception\ServiceException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Fetches and caches a platform's published JWKS, returning it as the
 * array<kid, Key> shape firebase/php-jwt's JWT::decode() expects.
 *
 * Only RSA/RS256 entries are accepted: even though the platform is a
 * trusted party, a fetched JWKS is external input, and firebase/php-jwt
 * itself will happily build an HS256 (symmetric) Key out of a JWK entry
 * that declares one (see JWK::parseKey()). We filter those out before
 * they can ever reach JWT::decode(), rather than relying solely on the
 * caller to keep using this keyset correctly.
 */
final class JwksFetcher
{
    private const DEFAULT_CACHE_TTL_SECONDS = 3600;
    private const ACCEPTED_ALGORITHM = 'RS256';

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly CacheInterface $cache,
        private readonly int $cacheTtlSeconds = self::DEFAULT_CACHE_TTL_SECONDS,
    ) {
    }

    /**
     * @return array<string, Key>
     */
    public function fetch(string $jwksUrl): array
    {
        $document = $this->fetchDocument($jwksUrl);
        $filtered = $this->filterToAcceptableRsaKeys($document);

        if ($filtered['keys'] === []) {
            throw new ServiceException(sprintf(
                'JWKS from "%s" did not contain any supported RSA/RS256 keys.',
                $jwksUrl,
            ));
        }

        return JWK::parseKeySet($filtered, self::ACCEPTED_ALGORITHM);
    }

    /**
     * @return array{keys: list<array<string, mixed>>}
     */
    private function fetchDocument(string $jwksUrl): array
    {
        $cacheKey = CacheKeyBuilder::build('jwks', $jwksUrl);

        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            /** @var array{keys: list<array<string, mixed>>} $cached */
            return $cached;
        }

        $request = $this->requestFactory
            ->createRequest('GET', $jwksUrl)
            ->withHeader('Accept', 'application/json');

        $response = $this->httpClient->sendRequest($request);

        if ($response->getStatusCode() !== 200) {
            throw new ServiceException(sprintf(
                'Fetching JWKS from "%s" failed with HTTP status %d.',
                $jwksUrl,
                $response->getStatusCode(),
            ));
        }

        try {
            $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ServiceException(
                sprintf('JWKS response from "%s" was not valid JSON.', $jwksUrl),
                previous: $exception,
            );
        }

        if (!is_array($decoded) || !isset($decoded['keys']) || !is_array($decoded['keys'])) {
            throw new ServiceException(sprintf(
                'JWKS response from "%s" did not contain a "keys" array.',
                $jwksUrl,
            ));
        }

        /** @var array{keys: list<array<string, mixed>>} $decoded */
        $this->cache->set($cacheKey, $decoded, $this->cacheTtlSeconds);

        return $decoded;
    }

    /**
     * @param array{keys: list<array<string, mixed>>} $document
     * @return array{keys: list<array<string, mixed>>}
     */
    private function filterToAcceptableRsaKeys(array $document): array
    {
        $acceptable = [];

        foreach ($document['keys'] as $entry) {
            if (($entry['kty'] ?? null) !== 'RSA') {
                continue;
            }

            $alg = $entry['alg'] ?? null;
            if ($alg !== null && $alg !== self::ACCEPTED_ALGORITHM) {
                continue;
            }

            $acceptable[] = $entry;
        }

        return ['keys' => $acceptable];
    }
}
