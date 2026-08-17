<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Services\Nrps;

use PhpLti\Lti1p3\Exception\ServiceException;

/**
 * A single roster entry from a Names and Role Provisioning Service fetch.
 * `roles` uses the same open LTI/LIS role vocabulary as the launch `roles`
 * claim — deliberately a raw list of URIs, not an enum (see Roles).
 */
final class Member
{
    /**
     * @param list<string> $roles
     */
    private function __construct(
        public readonly string $userId,
        public readonly string $status,
        public readonly ?string $name,
        public readonly array $roles,
        public readonly ?string $email,
        public readonly ?string $picture,
        public readonly ?string $givenName,
        public readonly ?string $familyName,
        public readonly ?string $middleName,
    ) {
    }

    public static function fromResponseData(mixed $data): self
    {
        if (!is_array($data)) {
            throw new ServiceException('NRPS member entry was not a JSON object.');
        }

        $userId = $data['user_id'] ?? null;
        if (!is_string($userId) || $userId === '') {
            throw new ServiceException('NRPS member entry is missing "user_id".');
        }

        $status = $data['status'] ?? null;
        $rolesValue = $data['roles'] ?? [];
        $roles = is_array($rolesValue) ? array_values(array_filter($rolesValue, 'is_string')) : [];

        return new self(
            $userId,
            is_string($status) && $status !== '' ? $status : 'Active',
            is_string($data['name'] ?? null) ? $data['name'] : null,
            $roles,
            is_string($data['email'] ?? null) ? $data['email'] : null,
            is_string($data['picture'] ?? null) ? $data['picture'] : null,
            is_string($data['given_name'] ?? null) ? $data['given_name'] : null,
            is_string($data['family_name'] ?? null) ? $data['family_name'] : null,
            is_string($data['middle_name'] ?? null) ? $data['middle_name'] : null,
        );
    }
}
