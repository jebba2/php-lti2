<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use PhpLti\Lti1p3\Exception\LtiException;
use PhpLti\Lti1p3\Services\AccessTokenService;
use PhpLti\Lti1p3\Services\Ags\ActivityProgress;
use PhpLti\Lti1p3\Services\Ags\AssignmentsGradesService;
use PhpLti\Lti1p3\Services\Ags\GradingProgress;
use PhpLti\Lti1p3\Services\Ags\Result;
use PhpLti\Lti1p3\Services\Ags\Score;
use PhpLti\Lti1p3Example\Bootstrap;
use PhpLti\Lti1p3Example\Render;

session_start();

/** @var array<string, mixed>|null $launch */
$launch = $_SESSION['launch'] ?? null;
if ($launch === null) {
    Render::error('No launch in session', 'Launch this tool from the simulator first: http://localhost:8001/');
}

/** @var array<string, mixed>|null $agsData */
$agsData = $launch['ags_endpoint'] ?? null;
$lineItemUrl = $agsData['line_item_url'] ?? null;
if (!is_string($lineItemUrl)) {
    Render::error('AGS not available', 'This launch was not granted a single AGS line item.');
}

$subject = $launch['subject'] ?? null;
if (!is_string($subject)) {
    Render::error('AGS not available', 'This was an anonymous launch — AGS needs a user id to score.');
}

$factory = new HttpFactory();
$httpClient = new Client();
$accessTokenService = new AccessTokenService($httpClient, $factory, $factory, Bootstrap::cache());
$ags = new AssignmentsGradesService($httpClient, $factory, $factory, $accessTokenService);
$registration = Bootstrap::registration();

try {
    $ags->publishScore($registration, $lineItemUrl, new Score(
        userId: $subject,
        activityProgress: ActivityProgress::Completed,
        gradingProgress: GradingProgress::FullyGraded,
        scoreGiven: 8.5,
        scoreMaximum: 10.0,
    ));

    $results = $ags->listResults($registration, $lineItemUrl);
} catch (LtiException $exception) {
    Render::error('AGS call failed', $exception->getMessage());
}

$rowsHtml = implode('', array_map(
    static fn (Result $result): string => sprintf(
        '<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
        htmlspecialchars($result->userId, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars((string) $result->resultScore, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars((string) $result->resultMaximum, ENT_QUOTES, 'UTF-8'),
    ),
    $results,
));
$subjectHtml = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');

echo Render::page('AGS score published', <<<HTML
<p>Published a score of 8.5 / 10.0 for <code>{$subjectHtml}</code>, then read the results back
from the platform (a real HTTP round trip to the simulator's AGS endpoints):</p>
<table>
<tr><th>User</th><th>Score</th><th>Max</th></tr>
{$rowsHtml}
</table>
<p><a href="index.php">Back</a> — re-launch from <a href="http://localhost:8001/">the simulator</a> to try again</p>
HTML);
