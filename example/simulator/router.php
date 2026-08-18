<?php

declare(strict_types=1);

// Router for `php -S localhost:8001 simulator/router.php`. Plays the role
// of the platform (like Brightspace) for local end-to-end testing of the
// example tool without a real LMS: an OIDC auth endpoint, JWKS, an OAuth2
// token endpoint that verifies the tool's real client-assertion JWT, and
// AGS/NRPS endpoints. Not part of the library — this is demo-only code
// standing in for something Brightspace would otherwise do.

require __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use PhpLti\Lti1p3\Http\FormPostRenderer;
use PhpLti\Lti1p3\Message\ClaimUris;
use PhpLti\Lti1p3\Registration\ToolKeyPair;
use PhpLti\Lti1p3\Security\Jwt\JwksBuilder;
use PhpLti\Lti1p3Example\Bootstrap;
use PhpLti\Lti1p3Example\Render;
use PhpLti\Lti1p3Example\Simulator\Database;

/**
 * @return array<string, mixed>
 */
function simulatorConfig(): array
{
    return Bootstrap::config();
}

function simulatorKeyPair(): ToolKeyPair
{
    $config = simulatorConfig();
    $keysDir = __DIR__ . '/../working/keys';
    $kid = $config['simulator_kid'];
    $privateKey = file_get_contents($keysDir . "/{$kid}.private.pem");
    $publicKey = file_get_contents($keysDir . "/{$kid}.public.pem");
    if ($privateKey === false || $publicKey === false) {
        throw new \RuntimeException('Missing simulator key files — run: php bin/setup.php');
    }

    return new ToolKeyPair($kid, $privateKey, $publicKey);
}

function database(): Database
{
    return new Database(__DIR__ . '/../working/simulator-db.json');
}

/**
 * Fetches the tool's own JWKS over real HTTP and verifies the client
 * assertion JWT the tool sent — the simulator's own (small, non-library)
 * mirror of what JwksFetcher + JwtValidator do on the tool side.
 */
function verifyToolClientAssertion(string $clientAssertion, string $expectedAudience): object
{
    $config = simulatorConfig();
    $client = new Client();
    $response = $client->request('GET', $config['tool_base_url'] . '/jwks.php');
    /** @var array{keys: list<array<string, mixed>>} $jwks */
    $jwks = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    $keySet = JWK::parseKeySet($jwks, 'RS256');

    $claims = JWT::decode($clientAssertion, $keySet);

    if ($claims->aud !== $expectedAudience) {
        throw new \RuntimeException('client_assertion "aud" does not match the expected audience.');
    }

    if ($claims->iss !== $claims->sub) {
        throw new \RuntimeException('client_assertion "iss" and "sub" must match.');
    }

    return $claims;
}

function issueAccessToken(string $scope): string
{
    $keyPair = simulatorKeyPair();
    $now = time();

    return JWT::encode(
        ['iss' => 'simulator', 'scope' => $scope, 'iat' => $now, 'exp' => $now + 3600],
        $keyPair->privateKey,
        'RS256',
        $keyPair->kid,
    );
}

/**
 * Exits with a 401 JSON error if the request lacks a valid bearer token;
 * otherwise returns normally.
 */
function requireValidAccessToken(): void
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($header, 'Bearer ')) {
        http_response_code(401);
        echo json_encode(['error' => 'missing_bearer_token'], JSON_THROW_ON_ERROR);
        exit;
    }

    $token = substr($header, strlen('Bearer '));
    $keyPair = simulatorKeyPair();

    try {
        JWT::decode($token, new \Firebase\JWT\Key($keyPair->publicKey, 'RS256'));
    } catch (\Throwable) {
        http_response_code(401);
        echo json_encode(['error' => 'invalid_access_token'], JSON_THROW_ON_ERROR);
        exit;
    }
}

function jsonResponse(int $status, mixed $data): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    exit;
}

$method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$requestUri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
$path = parse_url($requestUri, PHP_URL_PATH);
$path = is_string($path) ? rtrim($path, '/') : '';
if ($path === '') {
    $path = '/';
}

// --- Landing page: plays the role of "here's a course with a tool link in it". ---
if ($method === 'GET' && $path === '/') {
    $config = simulatorConfig();
    $toolLoginUrl = $config['tool_base_url'] . '/login.php';
    $baseParams = [
        'iss' => $config['simulator_base_url'],
        'login_hint' => 'demo-instructor-1',
        'target_link_uri' => $config['tool_base_url'] . '/launch.php',
        'lti_deployment_id' => $config['deployment_id'],
    ];
    $resourceLinkUrl = $toolLoginUrl . '?' . http_build_query($baseParams + ['lti_message_hint' => 'resource_link']);
    $deepLinkingUrl = $toolLoginUrl . '?' . http_build_query($baseParams + ['lti_message_hint' => 'deep_linking']);

    echo Render::page('php-lti platform simulator', <<<HTML
    <p>This stands in for a platform like D2L Brightspace, so the example tool can be launched
    and tested locally without a real LMS. It's demo-only code — not part of the library.</p>
    <p><a class="button" href="{$resourceLinkUrl}">Launch as a resource link</a></p>
    <p><a class="button" href="{$deepLinkingUrl}">Launch as a Deep Linking request</a></p>
    HTML);
    exit;
}

// --- Simulator's own JWKS: the tool verifies id_tokens against this. ---
if ($method === 'GET' && $path === '/jwks') {
    $jwk = (new JwksBuilder())->buildJwk(simulatorKeyPair());
    jsonResponse(200, ['keys' => [$jwk]]);
}

// --- OIDC authentication endpoint: the tool redirects the browser here. ---
if ($method === 'GET' && $path === '/authenticate') {
    $clientId = $_GET['client_id'] ?? null;
    $redirectUri = $_GET['redirect_uri'] ?? null;
    $loginHint = $_GET['login_hint'] ?? null;
    $state = $_GET['state'] ?? null;
    $nonce = $_GET['nonce'] ?? null;
    $messageHint = $_GET['lti_message_hint'] ?? 'resource_link';

    if (!is_string($clientId) || !is_string($redirectUri) || !is_string($loginHint) || !is_string($state) || !is_string($nonce)) {
        Render::error('Bad authentication request', 'Missing one of client_id/redirect_uri/login_hint/state/nonce.');
    }

    $config = simulatorConfig();
    $now = time();
    $claims = [
        'iss' => $config['simulator_base_url'],
        'aud' => $clientId,
        'exp' => $now + 300,
        'iat' => $now,
        'nonce' => $nonce,
        'sub' => $loginHint,
        ClaimUris::VERSION => '1.3.0',
        ClaimUris::DEPLOYMENT_ID => $config['deployment_id'],
        ClaimUris::TARGET_LINK_URI => $config['tool_base_url'] . '/launch.php',
        ClaimUris::ROLES => ['http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor'],
        ClaimUris::CONTEXT => ['id' => 'demo-context-1', 'title' => 'Demo Course', 'type' => ['CourseSection']],
        ClaimUris::TOOL_PLATFORM => ['guid' => 'simulator-guid', 'name' => 'php-lti Simulator'],
        ClaimUris::CUSTOM => ['demo_custom_param' => 'hello-from-the-simulator'],
    ];

    if ($messageHint === 'deep_linking') {
        $claims[ClaimUris::MESSAGE_TYPE] = 'LtiDeepLinkingRequest';
        $claims[ClaimUris::DEEP_LINKING_SETTINGS] = [
            'deep_link_return_url' => $config['simulator_base_url'] . '/deep-link-return',
            'accept_types' => ['ltiResourceLink'],
            'accept_multiple' => false,
            'data' => 'demo-correlation-id',
        ];
    } else {
        $lineItem = database()->ensureDefaultLineItem();
        $claims[ClaimUris::MESSAGE_TYPE] = 'LtiResourceLinkRequest';
        $claims[ClaimUris::RESOURCE_LINK] = ['id' => 'demo-resource-link', 'title' => 'Demo Resource Link'];
        $claims[ClaimUris::AGS_ENDPOINT] = [
            'scope' => [
                'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem',
                'https://purl.imsglobal.org/spec/lti-ags/scope/score',
                'https://purl.imsglobal.org/spec/lti-ags/scope/result.readonly',
            ],
            'lineitems' => $config['simulator_base_url'] . '/ags/lineitems',
            'lineitem' => $config['simulator_base_url'] . '/ags/lineitems/' . $lineItem['id'],
        ];
        $claims[ClaimUris::NRPS_ENDPOINT] = [
            'context_memberships_url' => $config['simulator_base_url'] . '/nrps/memberships',
            'service_versions' => ['2.0'],
        ];
    }

    $keyPair = simulatorKeyPair();
    $idToken = JWT::encode($claims, $keyPair->privateKey, 'RS256', $keyPair->kid);

    $factory = new Psr17Factory();
    $response = (new FormPostRenderer($factory, $factory))->render($redirectUri, ['state' => $state, 'id_token' => $idToken]);

    http_response_code($response->getStatusCode());
    foreach ($response->getHeaders() as $name => $values) {
        foreach ($values as $value) {
            header("{$name}: {$value}", false);
        }
    }
    echo (string) $response->getBody();
    exit;
}

// --- OAuth2 token endpoint: verifies the tool's client_assertion for real. ---
if ($method === 'POST' && $path === '/token') {
    // application/x-www-form-urlencoded — PHP already parses this into $_POST.
    $clientAssertion = $_POST['client_assertion'] ?? null;
    $scope = is_string($_POST['scope'] ?? null) ? $_POST['scope'] : '';

    if (!is_string($clientAssertion)) {
        jsonResponse(400, ['error' => 'invalid_request', 'error_description' => 'Missing client_assertion.']);
    }

    $config = simulatorConfig();

    // Mirrors the tool's Registration: the expected audience is the token
    // endpoint unless a platform_audience override is configured.
    $expectedAudience = is_string($config['platform_audience'] ?? null)
        ? $config['platform_audience']
        : $config['simulator_base_url'] . '/token';

    try {
        verifyToolClientAssertion($clientAssertion, $expectedAudience);
    } catch (\Throwable $exception) {
        jsonResponse(400, ['error' => 'invalid_client', 'error_description' => $exception->getMessage()]);
    }

    jsonResponse(200, [
        'access_token' => issueAccessToken($scope),
        'token_type' => 'Bearer',
        'expires_in' => 3600,
        'scope' => $scope,
    ]);
}

// --- Assignment and Grades Service ---
if (str_starts_with($path, '/ags/lineitems')) {
    requireValidAccessToken();
    $database = database();

    if ($method === 'GET' && $path === '/ags/lineitems') {
        jsonResponse(200, $database->listLineItems());
    }

    if ($method === 'POST' && $path === '/ags/lineitems') {
        $lineItem = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
        jsonResponse(201, $database->createLineItem($lineItem));
    }

    if (preg_match('#^/ags/lineitems/([^/]+)/scores$#', $path, $matches) === 1) {
        if ($method !== 'POST') {
            jsonResponse(405, ['error' => 'method_not_allowed']);
        }

        $score = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
        $database->recordScore($matches[1], $score);
        jsonResponse(200, ['success' => true]);
    }

    if (preg_match('#^/ags/lineitems/([^/]+)/results$#', $path, $matches) === 1) {
        if ($method !== 'GET') {
            jsonResponse(405, ['error' => 'method_not_allowed']);
        }

        $lineItem = $database->getLineItem($matches[1]);
        $results = array_map(
            static fn (array $score): array => [
                'id' => simulatorConfig()['simulator_base_url'] . "/ags/lineitems/{$matches[1]}/results/{$score['userId']}",
                'scoreOf' => simulatorConfig()['simulator_base_url'] . "/ags/lineitems/{$matches[1]}",
                'userId' => $score['userId'],
                'resultScore' => $score['scoreGiven'] ?? null,
                'resultMaximum' => $score['scoreMaximum'] ?? null,
            ],
            $database->listResults($matches[1]),
        );
        jsonResponse(200, $results);
    }

    if (preg_match('#^/ags/lineitems/([^/]+)$#', $path, $matches) === 1) {
        $lineItem = $database->getLineItem($matches[1]);

        if ($method === 'GET') {
            $lineItem === null ? jsonResponse(404, ['error' => 'not_found']) : jsonResponse(200, $lineItem);
        }

        if ($method === 'PUT') {
            $updated = $database->updateLineItem($matches[1], json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR));
            $updated === null ? jsonResponse(404, ['error' => 'not_found']) : jsonResponse(200, $updated);
        }

        if ($method === 'DELETE') {
            $database->deleteLineItem($matches[1]);
            jsonResponse(204, null);
        }
    }

    jsonResponse(404, ['error' => 'not_found']);
}

// --- Names and Role Provisioning Service ---
if ($method === 'GET' && $path === '/nrps/memberships') {
    requireValidAccessToken();
    jsonResponse(200, [
        'id' => simulatorConfig()['simulator_base_url'] . '/nrps/memberships',
        'members' => [
            [
                'status' => 'Active',
                'name' => 'Ada Lovelace',
                'user_id' => 'demo-instructor-1',
                'roles' => ['http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor'],
                'email' => 'ada@example.com',
            ],
            [
                'status' => 'Active',
                'name' => 'Alan Turing',
                'user_id' => 'demo-learner-1',
                'roles' => ['http://purl.imsglobal.org/vocab/lis/v2/membership#Learner'],
                'email' => 'alan@example.com',
            ],
        ],
    ]);
}

// --- Deep Linking response landing page: verifies the tool's signed JWT. ---
if ($method === 'POST' && $path === '/deep-link-return') {
    $jwt = $_POST['JWT'] ?? null;
    if (!is_string($jwt)) {
        Render::error('Deep Linking response missing', 'Expected a form field named "JWT".');
    }

    $client = new Client();
    $response = $client->request('GET', simulatorConfig()['tool_base_url'] . '/jwks.php');
    /** @var array{keys: list<array<string, mixed>>} $toolJwks */
    $toolJwks = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    $keySet = JWK::parseKeySet($toolJwks, 'RS256');

    try {
        $claims = JWT::decode($jwt, $keySet);
    } catch (\Throwable $exception) {
        Render::error('Deep Linking response verification failed', $exception->getMessage());
    }

    $contentItems = $claims->{ClaimUris::CONTENT_ITEMS} ?? [];
    $itemsHtml = implode('', array_map(
        static fn (object $item): string => '<li>' . htmlspecialchars(($item->type ?? '?') . ': ' . ($item->title ?? $item->url ?? ''), ENT_QUOTES, 'UTF-8') . '</li>',
        $contentItems,
    ));

    echo Render::page('Deep Linking response received and verified', <<<HTML
    <p>The tool's signed response JWT verified successfully against its own JWKS.</p>
    <p><strong>Selected content items:</strong></p>
    <ul>{$itemsHtml}</ul>
    <p><a href="/">Back</a></p>
    HTML);
    exit;
}

http_response_code(404);
echo "Not found: {$method} {$path}";
