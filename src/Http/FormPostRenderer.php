<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Renders a PSR-7 response containing an auto-submitting HTML form, per
 * the `response_mode=form_post` pattern LTI 1.3 uses for both the platform
 * auth response and (in reverse) the tool's Deep Linking response.
 * Framework-agnostic and not specific to Deep Linking, so any future
 * form_post use (Submission Review, Platform Notification Service) reuses
 * this instead of re-implementing HTML templating.
 */
final class FormPostRenderer
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    /**
     * @param array<string, string> $fields
     */
    public function render(string $targetUrl, array $fields): ResponseInterface
    {
        $inputs = '';
        foreach ($fields as $name => $value) {
            $inputs .= sprintf(
                "<input type=\"hidden\" name=\"%s\" value=\"%s\">\n",
                $this->escape($name),
                $this->escape($value),
            );
        }

        $escapedTargetUrl = $this->escape($targetUrl);

        $html = <<<HTML
        <!DOCTYPE html>
        <html>
        <head><title>Continue</title></head>
        <body onload="document.forms[0].submit()">
        <form action="{$escapedTargetUrl}" method="POST">
        {$inputs}<noscript><button type="submit">Continue</button></noscript>
        </form>
        </body>
        </html>
        HTML;

        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', 'text/html; charset=UTF-8')
            ->withBody($this->streamFactory->createStream($html));
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
