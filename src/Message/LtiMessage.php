<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Message;

use PhpLti\Lti1p3\Exception\InvalidLaunchException;
use PhpLti\Lti1p3\Exception\InvalidLaunchReason;
use PhpLti\Lti1p3\Message\Claims\AgsEndpoint;
use PhpLti\Lti1p3\Message\Claims\ClaimAccessor;
use PhpLti\Lti1p3\Message\Claims\NrpsEndpoint;
use PhpLti\Lti1p3\Message\Claims\Context;
use PhpLti\Lti1p3\Message\Claims\LaunchPresentation;
use PhpLti\Lti1p3\Message\Claims\Lis;
use PhpLti\Lti1p3\Message\Claims\Roles;
use PhpLti\Lti1p3\Message\Claims\ToolPlatform;

/**
 * Base for every LTI message type (resource link launch, deep linking
 * request, ...), holding the claims common to all of them. Concrete
 * subclasses add their message-type-specific claims (e.g.
 * LtiResourceLinkRequest's `resource_link`).
 *
 * Hydrated once, immediately from the validated claims array — nothing
 * downstream of this class touches raw claim arrays for these fields.
 */
abstract class LtiMessage
{
    public const LTI_VERSION = '1.3.0';

    /**
     * @param array<string, string> $custom
     * @param list<string>|null $roleScopeMentor
     * @param array<string, mixed> $rawClaims
     */
    protected function __construct(
        public readonly string $messageType,
        public readonly string $version,
        public readonly string $deploymentId,
        public readonly string $targetLinkUri,
        public readonly ?string $subject,
        public readonly Roles $roles,
        public readonly ?Context $context,
        public readonly ?ToolPlatform $toolPlatform,
        public readonly ?LaunchPresentation $launchPresentation,
        public readonly ?Lis $lis,
        public readonly ?array $roleScopeMentor,
        public readonly array $custom,
        public readonly array $rawClaims,
    ) {
    }

    /**
     * @param array<string, mixed> $claims
     * @return array{
     *     messageType: string,
     *     version: string,
     *     deploymentId: string,
     *     targetLinkUri: string,
     *     subject: ?string,
     *     roles: Roles,
     *     context: ?Context,
     *     toolPlatform: ?ToolPlatform,
     *     launchPresentation: ?LaunchPresentation,
     *     lis: ?Lis,
     *     roleScopeMentor: ?list<string>,
     *     custom: array<string, string>,
     *     rawClaims: array<string, mixed>,
     * }
     */
    protected static function commonPropertiesFromClaims(array $claims, string $expectedMessageType): array
    {
        $messageType = ClaimAccessor::requireString($claims, ClaimUris::MESSAGE_TYPE, 'LTI message');
        if ($messageType !== $expectedMessageType) {
            throw new InvalidLaunchException(
                InvalidLaunchReason::UnexpectedMessageType,
                sprintf('Expected message_type "%s", got "%s".', $expectedMessageType, $messageType),
            );
        }

        $version = ClaimAccessor::requireString($claims, ClaimUris::VERSION, 'LTI message');
        if ($version !== self::LTI_VERSION) {
            throw new InvalidLaunchException(
                InvalidLaunchReason::UnsupportedVersion,
                sprintf('Expected LTI version "%s", got "%s".', self::LTI_VERSION, $version),
            );
        }

        $customClaim = ClaimAccessor::optionalArray($claims, ClaimUris::CUSTOM) ?? [];
        /** @var array<string, string> $custom */
        $custom = array_filter($customClaim, 'is_string');

        $roleScopeMentor = ClaimAccessor::optionalStringList($claims, ClaimUris::ROLE_SCOPE_MENTOR);

        return [
            'messageType' => $messageType,
            'version' => $version,
            'deploymentId' => ClaimAccessor::requireString($claims, ClaimUris::DEPLOYMENT_ID, 'LTI message'),
            'targetLinkUri' => ClaimAccessor::requireString($claims, ClaimUris::TARGET_LINK_URI, 'LTI message'),
            'subject' => ClaimAccessor::optionalString($claims, 'sub'),
            'roles' => Roles::fromClaimValue($claims[ClaimUris::ROLES] ?? null),
            'context' => Context::fromClaimValue($claims[ClaimUris::CONTEXT] ?? null),
            'toolPlatform' => ToolPlatform::fromClaimValue($claims[ClaimUris::TOOL_PLATFORM] ?? null),
            'launchPresentation' => LaunchPresentation::fromClaimValue($claims[ClaimUris::LAUNCH_PRESENTATION] ?? null),
            'lis' => Lis::fromClaimValue($claims[ClaimUris::LIS] ?? null),
            'roleScopeMentor' => $roleScopeMentor === [] ? null : $roleScopeMentor,
            'custom' => $custom,
            'rawClaims' => $claims,
        ];
    }

    /**
     * The Assignment and Grades Service `endpoint` claim, if the platform
     * granted this launch access to it. Can appear on any authorized
     * message, not just a resource link launch, so it lives here rather
     * than on a specific subclass.
     */
    public function agsEndpoint(): ?AgsEndpoint
    {
        return AgsEndpoint::fromClaimValue($this->rawClaims[ClaimUris::AGS_ENDPOINT] ?? null);
    }

    /**
     * The Names and Role Provisioning Service `namesroleservice` claim, if
     * the platform granted this launch access to it.
     */
    public function nrpsEndpoint(): ?NrpsEndpoint
    {
        return NrpsEndpoint::fromClaimValue($this->rawClaims[ClaimUris::NRPS_ENDPOINT] ?? null);
    }
}
