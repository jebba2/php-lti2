<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use PhpLti\Lti1p3\Exception\LtiException;
use PhpLti\Lti1p3\OidcLogin\LoginInitiationHandler;
use PhpLti\Lti1p3Example\Bootstrap;
use PhpLti\Lti1p3Example\Render;

$factory = new Psr17Factory();
$request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();

$handler = new LoginInitiationHandler(Bootstrap::registrationRepository(), Bootstrap::cache(), $factory);

try {
    $response = $handler->handle($request, Bootstrap::config()['tool_base_url'] . '/launch.php');
} catch (LtiException $exception) {
    Render::error('Login initiation failed', $exception->getMessage());
}

http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header("{$name}: {$value}", false);
    }
}
echo (string) $response->getBody();
