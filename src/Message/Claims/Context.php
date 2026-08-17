<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Message\Claims;

/**
 * The `context` claim: the course/group the launch happened in. Optional —
 * not every launch (e.g. a tool-level launch) has a context.
 */
final class Context
{
    /**
     * @param list<string> $types
     */
    private function __construct(
        public readonly string $id,
        public readonly ?string $label,
        public readonly ?string $title,
        public readonly array $types,
    ) {
    }

    public static function fromClaimValue(mixed $value): ?self
    {
        if (!is_array($value)) {
            return null;
        }

        $id = ClaimAccessor::optionalString($value, 'id');
        if ($id === null) {
            return null;
        }

        return new self(
            $id,
            ClaimAccessor::optionalString($value, 'label'),
            ClaimAccessor::optionalString($value, 'title'),
            ClaimAccessor::optionalStringList($value, 'type'),
        );
    }
}
