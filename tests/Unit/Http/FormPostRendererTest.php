<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Unit\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PhpLti\Lti1p3\Http\FormPostRenderer;
use PHPUnit\Framework\TestCase;

final class FormPostRendererTest extends TestCase
{
    private function renderer(): FormPostRenderer
    {
        $factory = new Psr17Factory();

        return new FormPostRenderer($factory, $factory);
    }

    public function testRendersAnHtmlFormPostingToTheTargetUrl(): void
    {
        $targetUrl = 'https://platform.example.com/deep-link-return';
        $response = $this->renderer()->render($targetUrl, ['JWT' => 'the-jwt-value']);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));

        $html = (string) $response->getBody();
        self::assertStringContainsString('action="' . $targetUrl . '"', $html);
        self::assertStringContainsString('method="POST"', $html);
    }

    public function testUsesTheExactFieldNamesAndValuesGiven(): void
    {
        $html = (string) $this->renderer()->render('https://example.com', ['JWT' => 'abc.def.ghi'])->getBody();

        self::assertStringContainsString('name="JWT"', $html);
        self::assertStringContainsString('value="abc.def.ghi"', $html);
        self::assertStringNotContainsString('name="id_token"', $html);
    }

    public function testAutoSubmitsViaJavascriptWithANoscriptFallback(): void
    {
        $html = (string) $this->renderer()->render('https://example.com', ['JWT' => 'x'])->getBody();

        self::assertStringContainsString('.submit()', $html);
        self::assertStringContainsString('<noscript>', $html);
    }

    public function testEscapesHtmlSpecialCharactersInFieldValues(): void
    {
        $response = $this->renderer()->render('https://example.com', ['field' => '"><script>alert(1)</script>']);
        $html = (string) $response->getBody();

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testEscapesTheTargetUrl(): void
    {
        $maliciousUrl = 'https://example.com/"><script>x</script>';
        $html = (string) $this->renderer()->render($maliciousUrl, ['JWT' => 'x'])->getBody();

        self::assertStringNotContainsString('<script>x</script>', $html);
    }

    public function testRendersMultipleFields(): void
    {
        $html = (string) $this->renderer()->render('https://example.com', ['a' => '1', 'b' => '2'])->getBody();

        self::assertStringContainsString('name="a"', $html);
        self::assertStringContainsString('value="1"', $html);
        self::assertStringContainsString('name="b"', $html);
        self::assertStringContainsString('value="2"', $html);
    }
}
