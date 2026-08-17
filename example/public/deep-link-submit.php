<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use PhpLti\Lti1p3\Http\FormPostRenderer;
use PhpLti\Lti1p3\Message\DeepLinking\ContentItem\LtiResourceLinkContentItem;
use PhpLti\Lti1p3\Message\DeepLinking\LtiDeepLinkingResponse;
use PhpLti\Lti1p3Example\Bootstrap;
use PhpLti\Lti1p3Example\Render;

session_start();

/** @var array<string, mixed>|null $launch */
$launch = $_SESSION['launch'] ?? null;
$deepLinkingSettings = $launch['deep_linking_settings'] ?? null;
if ($launch === null || $deepLinkingSettings === null) {
    Render::error('No Deep Linking request in session', 'Launch this tool from the simulator first: http://localhost:8001/');
}

$deploymentId = $launch['deployment_id'] ?? null;
$deepLinkReturnUrl = $deepLinkingSettings['deep_link_return_url'] ?? null;
if (!is_string($deploymentId) || !is_string($deepLinkReturnUrl)) {
    Render::error('Deep Linking response failed', 'Missing deployment_id or deep_link_return_url in session.');
}

$title = trim((string) ($_POST['title'] ?? 'Selected demo item'));
$data = $deepLinkingSettings['data'] ?? null;

$registration = Bootstrap::registration();
$config = Bootstrap::config();

$response = new LtiDeepLinkingResponse(
    $registration,
    $deploymentId,
    $config['simulator_base_url'],
    [new LtiResourceLinkContentItem(
        url: $config['tool_base_url'] . '/launch.php',
        title: $title,
    )],
    data: is_string($data) ? $data : null,
);

$factory = new Psr17Factory();
$formPostResponse = (new FormPostRenderer($factory, $factory))->render(
    $deepLinkReturnUrl,
    ['JWT' => $response->toJwt()],
);

http_response_code($formPostResponse->getStatusCode());
foreach ($formPostResponse->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header("{$name}: {$value}", false);
    }
}
echo (string) $formPostResponse->getBody();
