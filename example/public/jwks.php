<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PhpLti\Lti1p3\Security\Jwt\JwksBuilder;
use PhpLti\Lti1p3Example\Bootstrap;

header('Content-Type: application/json');
echo json_encode((new JwksBuilder())->build(Bootstrap::registration()), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
