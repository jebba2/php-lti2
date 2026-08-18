<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3Example;

use PhpLti\Lti1p3\Registration\Registration;
use PhpLti\Lti1p3\Registration\ToolKeyPair;

/**
 * Wires this example's config/keys into the objects the library needs.
 * Deliberately simple (static config, single hardcoded registration) since
 * the point is to demonstrate the library's integration surface, not to be
 * a real multi-tenant tool.
 */
final class Bootstrap
{
    /** @var array<string, string>|null */
    private static ?array $config = null;

    /**
     * @return array<string, string>
     */
    public static function config(): array
    {
        if (self::$config === null) {
            $path = __DIR__ . '/../config/config.php';
            if (!is_file($path)) {
                throw new \RuntimeException('Missing config/config.php — run: php bin/setup.php');
            }

            /** @var array<string, string> $config */
            $config = require $path;
            self::$config = $config;
        }

        return self::$config;
    }

    public static function registration(): Registration
    {
        $config = self::config();
        $keysDir = __DIR__ . '/../working/keys';

        $privateKey = file_get_contents($keysDir . '/' . $config['tool_kid'] . '.private.pem');
        $publicKey = file_get_contents($keysDir . '/' . $config['tool_kid'] . '.public.pem');
        if ($privateKey === false || $publicKey === false) {
            throw new \RuntimeException('Missing tool key files — run: php bin/setup.php');
        }

        $toolKeyPair = new ToolKeyPair($config['tool_kid'], $privateKey, $publicKey);
        $simulatorBaseUrl = $config['simulator_base_url'];

        return new Registration(
            $simulatorBaseUrl,
            $config['client_id'],
            [$config['deployment_id']],
            $simulatorBaseUrl . '/authenticate',
            $simulatorBaseUrl . '/token',
            $simulatorBaseUrl . '/jwks',
            [$toolKeyPair],
            $config['platform_audience'] ?? null,
        );
    }

    public static function registrationRepository(): ConfigRegistrationRepository
    {
        return new ConfigRegistrationRepository(self::registration());
    }

    public static function cache(): FileCache
    {
        return new FileCache(__DIR__ . '/../working/cache');
    }
}
