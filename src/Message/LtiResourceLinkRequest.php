<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Message;

use PhpLti\Lti1p3\Message\Claims\Context;
use PhpLti\Lti1p3\Message\Claims\LaunchPresentation;
use PhpLti\Lti1p3\Message\Claims\Lis;
use PhpLti\Lti1p3\Message\Claims\ResourceLink;
use PhpLti\Lti1p3\Message\Claims\Roles;
use PhpLti\Lti1p3\Message\Claims\ToolPlatform;

/**
 * The core LTI message: a user clicking a resource link in the platform
 * and launching into the tool.
 */
final class LtiResourceLinkRequest extends LtiMessage
{
    public const MESSAGE_TYPE = 'LtiResourceLinkRequest';

    /**
     * @param array<string, string> $custom
     * @param list<string>|null $roleScopeMentor
     * @param array<string, mixed> $rawClaims
     */
    private function __construct(
        string $messageType,
        string $version,
        string $deploymentId,
        string $targetLinkUri,
        ?string $subject,
        Roles $roles,
        ?Context $context,
        ?ToolPlatform $toolPlatform,
        ?LaunchPresentation $launchPresentation,
        ?Lis $lis,
        ?array $roleScopeMentor,
        array $custom,
        array $rawClaims,
        public readonly ResourceLink $resourceLink,
    ) {
        parent::__construct(
            $messageType,
            $version,
            $deploymentId,
            $targetLinkUri,
            $subject,
            $roles,
            $context,
            $toolPlatform,
            $launchPresentation,
            $lis,
            $roleScopeMentor,
            $custom,
            $rawClaims,
        );
    }

    /**
     * @param array<string, mixed> $claims
     */
    public static function fromClaims(array $claims): self
    {
        $common = self::commonPropertiesFromClaims($claims, self::MESSAGE_TYPE);
        $resourceLink = ResourceLink::fromClaimValue(
            $claims[ClaimUris::RESOURCE_LINK] ?? null,
            self::MESSAGE_TYPE,
        );

        return new self(
            messageType: $common['messageType'],
            version: $common['version'],
            deploymentId: $common['deploymentId'],
            targetLinkUri: $common['targetLinkUri'],
            subject: $common['subject'],
            roles: $common['roles'],
            context: $common['context'],
            toolPlatform: $common['toolPlatform'],
            launchPresentation: $common['launchPresentation'],
            lis: $common['lis'],
            roleScopeMentor: $common['roleScopeMentor'],
            custom: $common['custom'],
            rawClaims: $common['rawClaims'],
            resourceLink: $resourceLink,
        );
    }
}
