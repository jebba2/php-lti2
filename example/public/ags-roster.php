<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use PhpLti\Lti1p3\Exception\LtiException;
use PhpLti\Lti1p3\Message\Claims\NrpsEndpoint;
use PhpLti\Lti1p3\Services\AccessTokenService;
use PhpLti\Lti1p3\Services\Nrps\Member;
use PhpLti\Lti1p3\Services\Nrps\NamesRoleService;
use PhpLti\Lti1p3Example\Bootstrap;
use PhpLti\Lti1p3Example\Render;

session_start();

/** @var array<string, mixed>|null $launch */
$launch = $_SESSION['launch'] ?? null;
if ($launch === null) {
    Render::error('No launch in session', 'Launch this tool from the simulator first: http://localhost:8001/');
}

/** @var array<string, mixed>|null $nrpsData */
$nrpsData = $launch['nrps_endpoint'] ?? null;
$contextMembershipsUrl = $nrpsData['context_memberships_url'] ?? null;
if (!is_string($contextMembershipsUrl)) {
    Render::error('NRPS not available', 'This launch was not granted access to the roster.');
}

/** @var list<string> $serviceVersions */
$serviceVersions = $nrpsData['service_versions'] ?? ['2.0'];
$endpoint = NrpsEndpoint::fromClaimValue([
    'context_memberships_url' => $contextMembershipsUrl,
    'service_versions' => $serviceVersions,
]);
if ($endpoint === null) {
    Render::error('NRPS not available', 'This launch was not granted access to the roster.');
}

$factory = new HttpFactory();
$httpClient = new Client();
$accessTokenService = new AccessTokenService($httpClient, $factory, $factory, Bootstrap::cache());
$nrps = new NamesRoleService($httpClient, $factory, $accessTokenService);

try {
    $members = $nrps->getMembers(Bootstrap::registration(), $endpoint);
} catch (LtiException $exception) {
    Render::error('NRPS call failed', $exception->getMessage());
}

$rowsHtml = implode('', array_map(
    static fn (Member $member): string => sprintf(
        '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
        htmlspecialchars($member->userId, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($member->name ?? '', ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($member->status, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars(implode(', ', $member->roles), ENT_QUOTES, 'UTF-8'),
    ),
    $members,
));

echo Render::page('NRPS roster', <<<HTML
<p>Fetched the course roster from the platform (a real HTTP round trip to the simulator's NRPS
endpoint):</p>
<table>
<tr><th>User ID</th><th>Name</th><th>Status</th><th>Roles</th></tr>
{$rowsHtml}
</table>
<p><a href="index.php">Back</a> — re-launch from <a href="http://localhost:8001/">the simulator</a> to try again</p>
HTML);
