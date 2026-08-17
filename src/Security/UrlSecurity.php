<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Security;

/**
 * Enforces https for platform/tool endpoints, the way Brightspace (and the
 * LTI 1.3 security model generally) expects in production — with an
 * exception for loopback addresses, which is what our own local dev/test
 * fixture Platform server necessarily uses (php's built-in server has no
 * TLS support). This is a plain function of the URL, not a configurable
 * allow-list: the loopback exception is narrow enough to not weaken
 * production use, and avoids needing a settings object threaded through
 * every constructor just for this one check.
 */
final class UrlSecurity
{
    private const LOOPBACK_HOSTS = ['127.0.0.1', 'localhost', '::1', '[::1]'];

    public static function isSecure(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme === 'https') {
            return true;
        }

        if ($scheme !== 'http') {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return $host !== null && in_array($host, self::LOOPBACK_HOSTS, true);
    }

    private function __construct()
    {
    }
}
