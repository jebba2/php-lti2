<?php

declare(strict_types=1);

// Router script for the `php -S` fixture Platform server used by tests
// (started via FixturePlatformServer). Records every incoming request and
// answers with whatever response was queued for that method+path through
// FixtureStore, or 404 if nothing was configured — this is a generic HTTP
// stub, not aware of JWKS/token/AGS/NRPS specifics, so every service
// milestone can reuse it by queueing whatever response it needs.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PhpLti\Lti1p3\Tests\Support\FixtureStore;

$fixtureDir = getenv('PHP_LTI_FIXTURE_DIR');
if ($fixtureDir === false || $fixtureDir === '') {
    http_response_code(500);
    echo 'PHP_LTI_FIXTURE_DIR environment variable is not set.';

    return;
}

$method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$requestUri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';

$path = parse_url($requestUri, PHP_URL_PATH);
if (!is_string($path)) {
    $path = '/';
}

/** @var array<string, string> $headers */
$headers = getallheaders();

$body = file_get_contents('php://input');
if ($body === false) {
    $body = '';
}

/** @var array<string, mixed> $query */
$query = $_GET;

FixtureStore::recordRequest($fixtureDir, $method, $path, $headers, $body, $query);

$response = FixtureStore::nextResponse($fixtureDir, $method, $path);

if ($response === null) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'no_fixture_configured',
        'method' => $method,
        'path' => $path,
    ], JSON_THROW_ON_ERROR);

    return;
}

http_response_code($response['status']);
foreach ($response['headers'] as $name => $value) {
    header($name . ': ' . $value);
}
echo $response['body'];
