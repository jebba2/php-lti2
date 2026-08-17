<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Message\Claims;

/**
 * The `roles` claim: a list of role URIs. Deliberately not a PHP enum —
 * the LIS/LTI role vocabulary is open-ended (institution-specific and
 * future spec additions are expected), and a backed enum would throw on
 * any role URI it doesn't already know about. Instead this wraps the raw
 * list and offers predicate helpers for the handful of common roles.
 */
final class Roles
{
    public const INSTRUCTOR = 'http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor';
    public const LEARNER = 'http://purl.imsglobal.org/vocab/lis/v2/membership#Learner';
    public const CONTENT_DEVELOPER = 'http://purl.imsglobal.org/vocab/lis/v2/membership#ContentDeveloper';
    public const TEACHING_ASSISTANT = 'http://purl.imsglobal.org/vocab/lis/v2/membership#TeachingAssistant';
    public const MENTOR = 'http://purl.imsglobal.org/vocab/lis/v2/membership#Mentor';
    public const ADMINISTRATOR = 'http://purl.imsglobal.org/vocab/lis/v2/institution/person#Administrator';

    /**
     * @param list<string> $roleUris
     */
    private function __construct(private readonly array $roleUris)
    {
    }

    public static function fromClaimValue(mixed $value): self
    {
        if (!is_array($value)) {
            return new self([]);
        }

        return new self(array_values(array_filter($value, 'is_string')));
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->roleUris;
    }

    public function has(string $roleUri): bool
    {
        return in_array($roleUri, $this->roleUris, true);
    }

    public function isInstructor(): bool
    {
        return $this->has(self::INSTRUCTOR);
    }

    public function isLearner(): bool
    {
        return $this->has(self::LEARNER);
    }

    public function isContentDeveloper(): bool
    {
        return $this->has(self::CONTENT_DEVELOPER);
    }

    public function isTeachingAssistant(): bool
    {
        return $this->has(self::TEACHING_ASSISTANT);
    }

    public function isMentor(): bool
    {
        return $this->has(self::MENTOR);
    }

    public function isAdministrator(): bool
    {
        return $this->has(self::ADMINISTRATOR);
    }
}
