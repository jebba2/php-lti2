<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Message;

/**
 * LTI 1.3 Core claim URIs, verified against the 1EdTech LTI 1.3
 * specification (purl.imsglobal.org/spec/lti/v1p3). Extended in later
 * milestones with Deep Linking / AGS / NRPS claim URIs.
 */
final class ClaimUris
{
    public const MESSAGE_TYPE = 'https://purl.imsglobal.org/spec/lti/claim/message_type';
    public const VERSION = 'https://purl.imsglobal.org/spec/lti/claim/version';
    public const DEPLOYMENT_ID = 'https://purl.imsglobal.org/spec/lti/claim/deployment_id';
    public const TARGET_LINK_URI = 'https://purl.imsglobal.org/spec/lti/claim/target_link_uri';
    public const RESOURCE_LINK = 'https://purl.imsglobal.org/spec/lti/claim/resource_link';
    public const ROLES = 'https://purl.imsglobal.org/spec/lti/claim/roles';
    public const CONTEXT = 'https://purl.imsglobal.org/spec/lti/claim/context';
    public const TOOL_PLATFORM = 'https://purl.imsglobal.org/spec/lti/claim/tool_platform';
    public const LAUNCH_PRESENTATION = 'https://purl.imsglobal.org/spec/lti/claim/launch_presentation';
    public const CUSTOM = 'https://purl.imsglobal.org/spec/lti/claim/custom';
    public const LIS = 'https://purl.imsglobal.org/spec/lti/claim/lis';
    public const ROLE_SCOPE_MENTOR = 'https://purl.imsglobal.org/spec/lti/claim/role_scope_mentor';
    public const AGS_ENDPOINT = 'https://purl.imsglobal.org/spec/lti-ags/claim/endpoint';
    public const NRPS_ENDPOINT = 'https://purl.imsglobal.org/spec/lti-nrps/claim/namesroleservice';
    public const DEEP_LINKING_SETTINGS = 'https://purl.imsglobal.org/spec/lti-dl/claim/deep_linking_settings';
    public const CONTENT_ITEMS = 'https://purl.imsglobal.org/spec/lti-dl/claim/content_items';
    public const DATA = 'https://purl.imsglobal.org/spec/lti-dl/claim/data';

    private function __construct()
    {
    }
}
