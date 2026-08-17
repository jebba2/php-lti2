#!/usr/bin/env php
<?php

declare(strict_types=1);

// Generates a new RSA signing key pair for this tool and prints the
// corresponding JWK (for publishing at your tool's JWKS endpoint) as JSON.
// Run `php bin/generate-keypair.php --help` for usage.
//
// Why: every LTI 1.3 tool registration needs its own RSA key pair to sign
// outbound JWTs (client assertions, deep linking responses) and to publish
// a JWKS the platform verifies them against. This is a one-time/per-rotation
// setup step, not something the library does at runtime — the library only
// ever receives PEM strings via Registration/ToolKeyPair.
//
// All argument parsing and generation logic lives in
// PhpLti\Lti1p3\Console\GenerateKeypairCommand so it can be unit tested
// directly; this script is just a thin bootstrap around it.

require dirname(__DIR__) . '/vendor/autoload.php';

use PhpLti\Lti1p3\Console\GenerateKeypairCommand;

$result = (new GenerateKeypairCommand())->run(array_slice($argv, 1));

if ($result->stdout !== '') {
    fwrite(STDOUT, $result->stdout);
}

if ($result->stderr !== '') {
    fwrite(STDERR, $result->stderr);
}

exit($result->exitCode);
