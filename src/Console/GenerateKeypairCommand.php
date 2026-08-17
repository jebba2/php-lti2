<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Console;

use PhpLti\Lti1p3\Security\Jwt\JwksBuilder;
use PhpLti\Lti1p3\Security\Jwt\KeyPairGenerator;

/**
 * Backs bin/generate-keypair.php. Kept as a class, rather than logic inline
 * in the script, so argument handling stays unit-testable: PHP's getopt()
 * only ever reads the real process argv, so testing it directly would mean
 * shelling out to a subprocess for every case. This class instead accepts
 * an arguments array and returns a CommandResult instead of touching
 * STDOUT/STDERR/exit() itself.
 */
final class GenerateKeypairCommand
{
    private const DEFAULT_BITS = 2048;

    /**
     * @param list<string> $arguments argv without the script name, e.g. array_slice($argv, 1)
     */
    public function run(array $arguments): CommandResult
    {
        $parsed = $this->parseArguments($arguments);

        if ($parsed['help']) {
            return new CommandResult(0, $this->usage());
        }

        $kid = $parsed['kid'];
        if ($kid === null || trim($kid) === '') {
            return new CommandResult(1, stderr: 'Error: --kid is required.' . PHP_EOL . PHP_EOL . $this->usage());
        }

        $bits = $parsed['bits'] ?? self::DEFAULT_BITS;
        if ($bits < 2048) {
            $message = 'Error: --bits must be at least 2048.' . PHP_EOL . PHP_EOL . $this->usage();

            return new CommandResult(1, stderr: $message);
        }

        $outDir = $parsed['outDir'] ?? $this->defaultOutputDirectory();

        if (!is_dir($outDir) && !mkdir($outDir, 0700, true) && !is_dir($outDir)) {
            $message = sprintf('Error: could not create output directory "%s".', $outDir) . PHP_EOL;

            return new CommandResult(1, stderr: $message);
        }

        $keyPair = (new KeyPairGenerator($bits))->generate($kid);

        $privateKeyPath = $outDir . '/' . $kid . '.private.pem';
        $publicKeyPath = $outDir . '/' . $kid . '.public.pem';

        file_put_contents($privateKeyPath, $keyPair->privateKey);
        chmod($privateKeyPath, 0600);
        file_put_contents($publicKeyPath, $keyPair->publicKey);

        $jwk = (new JwksBuilder())->buildJwk($keyPair);

        $stdout = sprintf('Wrote private key: %s', $privateKeyPath) . PHP_EOL
            . sprintf('Wrote public key:  %s', $publicKeyPath) . PHP_EOL
            . PHP_EOL
            . 'JWK (add this to your tool\'s published JWKS "keys" array):' . PHP_EOL
            . json_encode($jwk, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;

        return new CommandResult(0, $stdout);
    }

    /**
     * @param list<string> $arguments
     * @return array{help: bool, kid: ?string, bits: ?int, outDir: ?string}
     */
    private function parseArguments(array $arguments): array
    {
        $help = false;
        $kid = null;
        $bits = null;
        $outDir = null;

        foreach ($arguments as $argument) {
            if ($argument === '--help') {
                $help = true;
            } elseif (str_starts_with($argument, '--kid=')) {
                $kid = substr($argument, strlen('--kid='));
            } elseif (str_starts_with($argument, '--bits=')) {
                $bits = (int) substr($argument, strlen('--bits='));
            } elseif (str_starts_with($argument, '--out-dir=')) {
                $outDir = substr($argument, strlen('--out-dir='));
            }
        }

        return ['help' => $help, 'kid' => $kid, 'bits' => $bits, 'outDir' => $outDir];
    }

    private function defaultOutputDirectory(): string
    {
        $cwd = getcwd();

        return ($cwd === false ? '.' : $cwd) . '/working/keys';
    }

    private function usage(): string
    {
        return <<<USAGE
        Usage: php bin/generate-keypair.php --kid=<kid> [--bits=2048] [--out-dir=working/keys]

          --kid       Required. Key ID to embed in the JWK (e.g. "2026-07-key-1").
          --bits      Optional. RSA key size in bits, minimum 2048 (default: 2048).
          --out-dir   Optional. Directory to write <kid>.private.pem/<kid>.public.pem into
                      (default: working/keys, relative to the current directory). Created if missing.
          --help      Show this message.

        Generates a new RSA key pair for signing outbound tool JWTs, writes the
        PEM files, and prints the corresponding JWK entry as JSON on stdout so you
        can add it to your tool's published JWKS document.

        USAGE;
    }
}
