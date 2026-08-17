<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use PhpLti\Lti1p3\Exception\LtiException;
use PhpLti\Lti1p3\Message\DeepLinking\LtiDeepLinkingRequest;
use PhpLti\Lti1p3\Message\LtiResourceLinkRequest;
use PhpLti\Lti1p3\OidcLogin\LaunchValidator;
use PhpLti\Lti1p3\Security\Jwt\JwksFetcher;
use PhpLti\Lti1p3\Security\Jwt\JwtValidator;
use PhpLti\Lti1p3Example\Bootstrap;
use PhpLti\Lti1p3Example\Render;

/**
 * @param list<string> $roles
 */
function renderResourceLinkBody(LtiResourceLinkRequest $message, array $roles): string
{
    $rolesHtml = implode('', array_map(
        static fn (string $role): string => '<li>' . htmlspecialchars($role, ENT_QUOTES, 'UTF-8') . '</li>',
        $roles,
    ));
    $customHtml = $message->custom === []
        ? '<p><em>none</em></p>'
        : '<pre>' . htmlspecialchars(print_r($message->custom, true), ENT_QUOTES, 'UTF-8') . '</pre>';

    $actions = '';
    if ($message->agsEndpoint() !== null) {
        $actions .= '<p><a class="button" href="ags-score.php">Publish a demo score (AGS)</a></p>';
    }
    if ($message->nrpsEndpoint() !== null) {
        $actions .= '<p><a class="button" href="ags-roster.php">Fetch course roster (NRPS)</a></p>';
    }

    $subject = htmlspecialchars($message->subject ?? '(anonymous launch — no sub claim)', ENT_QUOTES, 'UTF-8');
    $deploymentId = htmlspecialchars($message->deploymentId, ENT_QUOTES, 'UTF-8');
    $resourceLinkId = htmlspecialchars($message->resourceLink->id, ENT_QUOTES, 'UTF-8');
    $resourceLinkTitle = htmlspecialchars($message->resourceLink->title ?? '(untitled)', ENT_QUOTES, 'UTF-8');

    return <<<HTML
    <p><strong>Subject:</strong> {$subject}</p>
    <p><strong>Deployment:</strong> {$deploymentId}</p>
    <p><strong>Resource link:</strong> {$resourceLinkId} — {$resourceLinkTitle}</p>
    <p><strong>Roles:</strong></p>
    <ul>{$rolesHtml}</ul>
    <p><strong>Custom parameters:</strong></p>
    {$customHtml}
    {$actions}
    HTML;
}

function renderDeepLinkingBody(LtiDeepLinkingRequest $message): string
{
    $settings = $message->deepLinkingSettings;
    $acceptTypesHtml = htmlspecialchars(implode(', ', $settings->acceptTypes), ENT_QUOTES, 'UTF-8');
    $returnUrl = htmlspecialchars($settings->deepLinkReturnUrl, ENT_QUOTES, 'UTF-8');

    return <<<HTML
    <p>The platform is asking this tool to let the user pick content to link back.</p>
    <p><strong>Accepted types:</strong> {$acceptTypesHtml}</p>
    <p><strong>Return URL:</strong> {$returnUrl}</p>
    <form method="post" action="deep-link-submit.php">
        <label>Title of the item to send back:
            <input type="text" name="title" value="Selected demo item" required>
        </label>
        <button type="submit">Send selection back to platform</button>
    </form>
    HTML;
}

session_start();

$factory = new Psr17Factory();
$request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();

$httpClient = new Client();
$httpFactory = new HttpFactory();
$jwksFetcher = new JwksFetcher($httpClient, $httpFactory, Bootstrap::cache());
$jwtValidator = new JwtValidator($jwksFetcher, Bootstrap::cache());
$validator = new LaunchValidator(Bootstrap::registrationRepository(), Bootstrap::cache(), $jwtValidator);

try {
    $message = $validator->validate($request);
} catch (LtiException $exception) {
    Render::error('Launch failed', $exception->getMessage());
}

$agsEndpoint = $message->agsEndpoint();
$nrpsEndpoint = $message->nrpsEndpoint();

// Stashed in the session because the id_token's `state` is single-use —
// it's already been consumed by LaunchValidator, so the follow-up AGS/NRPS
// demo actions (separate HTTP requests) can't re-validate a launch; they
// reuse what was already validated here instead.
$_SESSION['launch'] = [
    'message_type' => $message->messageType,
    'subject' => $message->subject,
    'roles' => $message->roles->all(),
    'custom' => $message->custom,
    'deployment_id' => $message->deploymentId,
    'ags_endpoint' => $agsEndpoint === null ? null : [
        'line_items_url' => $agsEndpoint->lineItemsUrl,
        'line_item_url' => $agsEndpoint->lineItemUrl,
        'scopes' => $agsEndpoint->scopes,
    ],
    'nrps_endpoint' => $nrpsEndpoint === null ? null : [
        'context_memberships_url' => $nrpsEndpoint->contextMembershipsUrl,
        'service_versions' => $nrpsEndpoint->serviceVersions,
    ],
];

if ($message instanceof LtiDeepLinkingRequest) {
    $_SESSION['launch']['deep_linking_settings'] = [
        'deep_link_return_url' => $message->deepLinkingSettings->deepLinkReturnUrl,
        'data' => $message->deepLinkingSettings->data,
    ];

    echo Render::page('Deep Linking request received', renderDeepLinkingBody($message));

    return;
}

if ($message instanceof LtiResourceLinkRequest) {
    echo Render::page('Resource link launch successful', renderResourceLinkBody($message, $message->roles->all()));

    return;
}

Render::error('Unsupported launch', 'Unhandled message type: ' . $message->messageType);
