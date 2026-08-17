<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Unit\Console;

use PhpLti\Lti1p3\Console\GenerateKeypairCommand;
use PhpLti\Lti1p3\Tests\Support\Filesystem;
use PHPUnit\Framework\TestCase;

final class GenerateKeypairCommandTest extends TestCase
{
    /** @var list<string> */
    private array $directoriesToClean = [];

    private ?string $originalCwd = null;

    protected function tearDown(): void
    {
        if ($this->originalCwd !== null) {
            chdir($this->originalCwd);
            $this->originalCwd = null;
        }

        foreach ($this->directoriesToClean as $directory) {
            Filesystem::removeDirectory($directory);
        }

        $this->directoriesToClean = [];
    }

    private function makeTempDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/php-lti-test-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $this->directoriesToClean[] = $directory;

        return $directory;
    }

    public function testHelpFlagPrintsUsageAndReturnsZero(): void
    {
        $result = (new GenerateKeypairCommand())->run(['--help']);

        self::assertSame(0, $result->exitCode);
        self::assertStringContainsString('Usage: php bin/generate-keypair.php', $result->stdout);
        self::assertSame('', $result->stderr);
    }

    public function testMissingKidReturnsErrorExitCode(): void
    {
        $result = (new GenerateKeypairCommand())->run([]);

        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('--kid is required', $result->stderr);
    }

    public function testBlankKidReturnsErrorExitCode(): void
    {
        $result = (new GenerateKeypairCommand())->run(['--kid=   ']);

        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('--kid is required', $result->stderr);
    }

    public function testBitsBelowMinimumReturnsErrorExitCode(): void
    {
        $result = (new GenerateKeypairCommand())->run(['--kid=kid-1', '--bits=512']);

        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('--bits must be at least 2048', $result->stderr);
    }

    public function testGeneratesRealKeyPairAndWritesPemFilesToOutDir(): void
    {
        $outDir = $this->makeTempDirectory() . '/keys';

        $result = (new GenerateKeypairCommand())->run(['--kid=kid-1', '--out-dir=' . $outDir]);

        self::assertSame(0, $result->exitCode);
        self::assertFileExists($outDir . '/kid-1.private.pem');
        self::assertFileExists($outDir . '/kid-1.public.pem');

        $privateKey = file_get_contents($outDir . '/kid-1.private.pem');
        self::assertIsString($privateKey);
        self::assertStringContainsString('BEGIN PRIVATE KEY', $privateKey);

        self::assertStringContainsString('"kid": "kid-1"', $result->stdout);
        self::assertStringContainsString('"kty": "RSA"', $result->stdout);
    }

    public function testPrivateKeyFileIsWrittenWithRestrictivePermissions(): void
    {
        $outDir = $this->makeTempDirectory() . '/keys';

        (new GenerateKeypairCommand())->run(['--kid=kid-1', '--out-dir=' . $outDir]);

        $permissions = fileperms($outDir . '/kid-1.private.pem');
        self::assertNotFalse($permissions);
        self::assertSame('0600', substr(sprintf('%o', $permissions), -4));
    }

    public function testCreatesOutputDirectoryWhenMissing(): void
    {
        $outDir = $this->makeTempDirectory() . '/nested/keys';
        self::assertDirectoryDoesNotExist($outDir);

        $result = (new GenerateKeypairCommand())->run(['--kid=kid-1', '--out-dir=' . $outDir]);

        self::assertSame(0, $result->exitCode);
        self::assertDirectoryExists($outDir);
    }

    public function testDefaultsToWorkingKeysUnderTheCurrentWorkingDirectory(): void
    {
        $this->originalCwd = getcwd() ?: '.';
        $tempDirectory = $this->makeTempDirectory();
        chdir($tempDirectory);

        $result = (new GenerateKeypairCommand())->run(['--kid=kid-1']);

        self::assertSame(0, $result->exitCode);
        self::assertFileExists($tempDirectory . '/working/keys/kid-1.private.pem');
    }
}
