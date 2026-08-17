<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Message\Claims;

use PhpLti\Lti1p3\Exception\InvalidLaunchException;
use PhpLti\Lti1p3\Exception\InvalidLaunchReason;

/**
 * Typed reads over the raw, dynamically-shaped claims array decoded from a
 * JWT. Centralizing this here means LtiMessage and every Claims value
 * object hydrate from stdClass/array data through one boundary, rather
 * than scattering is_string()/is_array() checks through business logic.
 */
final class ClaimAccessor
{
    /**
     * @param array<string, mixed> $data
     */
    public static function requireString(array $data, string $key, string $context): string
    {
        $value = $data[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw new InvalidLaunchException(
                InvalidLaunchReason::MissingRequiredClaim,
                sprintf('%s is missing required "%s".', $context, $key),
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function optionalInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function optionalBool(array $data, string $key): ?bool
    {
        $value = $data[$key] ?? null;

        return is_bool($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    public static function optionalStringList(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public static function optionalArray(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? $value : null;
    }
}
