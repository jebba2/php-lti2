<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Support;

/**
 * Small filesystem helpers shared across tests that create temporary
 * directories (fixture stores, generated keys) and need to clean them up.
 */
final class Filesystem
{
    public static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;
            is_dir($path) ? self::removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
