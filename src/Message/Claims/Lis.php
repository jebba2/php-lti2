<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Message\Claims;

/**
 * The `lis` claim: legacy identifiers carried over from LTI 1.1, still
 * sent by some platforms alongside the LTI 1.3 claims. All optional.
 */
final class Lis
{
    private function __construct(
        public readonly ?string $personSourcedId,
        public readonly ?string $courseOfferingSourcedId,
        public readonly ?string $courseSectionSourcedId,
    ) {
    }

    public static function fromClaimValue(mixed $value): ?self
    {
        if (!is_array($value)) {
            return null;
        }

        return new self(
            ClaimAccessor::optionalString($value, 'person_sourcedid'),
            ClaimAccessor::optionalString($value, 'course_offering_sourcedid'),
            ClaimAccessor::optionalString($value, 'course_section_sourcedid'),
        );
    }
}
