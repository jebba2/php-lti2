<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Cache;

/**
 * Builds PSR-16-safe cache keys from arbitrary parts. PSR-16 forbids the
 * characters {}()/\@: in cache keys, but our natural key material (issuer
 * URLs, JWKS URLs, client IDs, OAuth2 scopes) routinely contains them —
 * every cache consumer (JWKS cache, nonce/state store, access token cache)
 * should build its keys through here rather than concatenating raw values.
 */
final class CacheKeyBuilder
{
    public static function build(string $namespace, string ...$parts): string
    {
        $sanitizedNamespace = preg_replace('/[^A-Za-z0-9_.-]/', '_', $namespace);
        $hash = hash('sha256', implode("\0", $parts));

        return sprintf('lti1p3_%s_%s', $sanitizedNamespace, $hash);
    }
}
