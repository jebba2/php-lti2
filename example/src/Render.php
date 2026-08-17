<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3Example;

/**
 * Bare-bones HTML page wrapper — this example is about the LTI integration,
 * not front-end polish.
 */
final class Render
{
    public static function page(string $title, string $bodyHtml): string
    {
        $escapedTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>{$escapedTitle}</title>
            <style>
                body { font-family: system-ui, sans-serif; max-width: 780px; margin: 2rem auto; line-height: 1.5; padding: 0 1rem; }
                pre { background: #f4f4f4; padding: 1rem; overflow-x: auto; }
                code { background: #f4f4f4; padding: 0.1rem 0.3rem; }
                a.button { display: inline-block; padding: 0.5rem 1rem; background: #2a6; color: #fff; text-decoration: none; border-radius: 4px; margin: 0.25rem 0.25rem 0.25rem 0; }
                table { border-collapse: collapse; margin: 1rem 0; }
                td, th { border: 1px solid #ccc; padding: 0.25rem 0.5rem; text-align: left; }
                .error { color: #a00; background: #fee; padding: 1rem; border-radius: 4px; }
            </style>
        </head>
        <body>
        <h1>{$escapedTitle}</h1>
        {$bodyHtml}
        </body>
        </html>
        HTML;
    }

    public static function error(string $title, string $message): never
    {
        http_response_code(400);
        echo self::page($title, '<p class="error">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>');
        exit;
    }
}
