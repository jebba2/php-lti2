<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Support;

/**
 * A real `php -S` HTTP server standing in for the Platform in tests, so
 * PSR-18 client code (JWKS fetch, token exchange, AGS, NRPS) is exercised
 * against real HTTP rather than a mocked client. Script what it should
 * answer via queueResponse(), and inspect what it actually received via
 * receivedRequests() — both go through FixtureStore, the file-based control
 * channel this process and the router process share.
 */
final class FixturePlatformServer
{
    private const MAX_START_ATTEMPTS = 10;
    private const READY_POLL_ATTEMPTS = 50;
    private const READY_POLL_DELAY_MICROSECONDS = 20_000;

    /** @var array<int, self> */
    private static array $activeServers = [];
    private static bool $shutdownHandlerRegistered = false;

    private bool $stopped = false;

    /**
     * @param resource $process
     */
    private function __construct(
        private $process,
        private readonly int $port,
        private readonly string $fixtureDirectory,
    ) {
    }

    public static function start(): self
    {
        $routerScript = __DIR__ . '/fixture-platform-router.php';
        $lastError = 'unknown error';

        for ($attempt = 0; $attempt < self::MAX_START_ATTEMPTS; ++$attempt) {
            $port = random_int(20_000, 60_000);
            $fixtureDirectory = sys_get_temp_dir() . '/php-lti-fixture-' . bin2hex(random_bytes(8));
            mkdir($fixtureDirectory, 0700, true);

            $process = self::spawn($port, $fixtureDirectory, $routerScript);

            if ($process === false) {
                $lastError = 'proc_open failed';
                Filesystem::removeDirectory($fixtureDirectory);

                continue;
            }

            if (self::waitUntilReady('127.0.0.1', $port)) {
                $server = new self($process, $port, $fixtureDirectory);
                self::register($server);

                return $server;
            }

            proc_terminate($process);
            proc_close($process);
            Filesystem::removeDirectory($fixtureDirectory);
            $lastError = sprintf('fixture server did not become ready on port %d', $port);
        }

        throw new \RuntimeException('Could not start fixture platform server: ' . $lastError);
    }

    /**
     * @return resource|false
     */
    private static function spawn(int $port, string $fixtureDirectory, string $routerScript)
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $command = sprintf('exec php -S 127.0.0.1:%d %s', $port, escapeshellarg($routerScript));

        $previousEnvValue = getenv('PHP_LTI_FIXTURE_DIR');
        putenv('PHP_LTI_FIXTURE_DIR=' . $fixtureDirectory);

        try {
            $process = proc_open($command, $descriptorSpec, $pipes);
        } finally {
            putenv($previousEnvValue === false ? 'PHP_LTI_FIXTURE_DIR' : 'PHP_LTI_FIXTURE_DIR=' . $previousEnvValue);
        }

        if ($process !== false) {
            foreach ($pipes as $pipe) {
                stream_set_blocking($pipe, false);
            }
        }

        return $process;
    }

    /**
     * Polls with a real HTTP round-trip, not just a TCP connect: the OS can
     * accept the connection into its backlog slightly before the PHP
     * built-in server has finished bootstrapping the router script (which
     * itself loads the Composer autoloader), so a bare fsockopen() success
     * doesn't guarantee the server will actually answer yet.
     */
    private static function waitUntilReady(string $host, int $port): bool
    {
        for ($i = 0; $i < self::READY_POLL_ATTEMPTS; ++$i) {
            if (self::respondsToHttpRequest($host, $port)) {
                return true;
            }

            usleep(self::READY_POLL_DELAY_MICROSECONDS);
        }

        return false;
    }

    private static function respondsToHttpRequest(string $host, int $port): bool
    {
        $connection = @fsockopen($host, $port, $errno, $errstr, 0.05);
        if ($connection === false) {
            return false;
        }

        stream_set_timeout($connection, 0, 100_000);
        fwrite($connection, "GET / HTTP/1.0\r\nHost: {$host}\r\nConnection: close\r\n\r\n");
        $response = fread($connection, 15);
        fclose($connection);

        return is_string($response) && str_starts_with($response, 'HTTP/');
    }

    public function baseUrl(): string
    {
        return sprintf('http://127.0.0.1:%d', $this->port);
    }

    /**
     * @param array<string, string> $headers
     */
    public function queueResponse(string $method, string $path, int $status, array $headers, string $body): void
    {
        FixtureStore::queueResponse($this->fixtureDirectory, $method, $path, $status, $headers, $body);
    }

    /**
     * @return list<array{headers: array<string, string>, body: string, query: array<string, mixed>}>
     */
    public function receivedRequests(string $method, string $path): array
    {
        return FixtureStore::requestsFor($this->fixtureDirectory, $method, $path);
    }

    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }

        $this->stopped = true;
        self::unregister($this);

        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }

        Filesystem::removeDirectory($this->fixtureDirectory);
    }

    public function __destruct()
    {
        $this->stop();
    }

    private static function register(self $server): void
    {
        self::$activeServers[spl_object_id($server)] = $server;

        if (self::$shutdownHandlerRegistered) {
            return;
        }

        self::$shutdownHandlerRegistered = true;
        register_shutdown_function(static function (): void {
            foreach (self::$activeServers as $server) {
                $server->stop();
            }
        });
    }

    private static function unregister(self $server): void
    {
        unset(self::$activeServers[spl_object_id($server)]);
    }
}
