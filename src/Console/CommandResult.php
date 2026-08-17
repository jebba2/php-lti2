<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Console;

/**
 * The outcome of running a console command: an exit code plus the text that
 * should be written to stdout/stderr. Kept separate from actually writing to
 * the real streams so command logic can be unit tested without a subprocess.
 */
final class CommandResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout = '',
        public readonly string $stderr = '',
    ) {
    }
}
