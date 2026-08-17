<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Integration\Message\DeepLinking;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use PhpLti\Lti1p3\Message\ClaimUris;
use PhpLti\Lti1p3\Message\DeepLinking\ContentItem\LinkContentItem;
use PhpLti\Lti1p3\Message\DeepLinking\ContentItem\LtiResourceLinkContentItem;
use PhpLti\Lti1p3\Message\DeepLinking\LtiDeepLinkingResponse;
use PhpLti\Lti1p3\Registration\Registration;
use PhpLti\Lti1p3\Security\Jwt\JwksBuilder;
use PhpLti\Lti1p3\Security\Jwt\KeyPairGenerator;
use PHPUnit\Framework\TestCase;

final class LtiDeepLinkingResponseTest extends TestCase
{
    private const ISSUER = 'https://example.brightspace.com';
    private const CLIENT_ID = 'client-1';
    private const DEPLOYMENT_ID = 'deployment-1';

    private function registration(): Registration
    {
        return new Registration(
            self::ISSUER,
            self::CLIENT_ID,
            [self::DEPLOYMENT_ID],
            self::ISSUER . '/d2l/lti/authenticate',
            self::ISSUER . '/core/connect/token',
            self::ISSUER . '/d2l/.well-known/jwks',
            [(new KeyPairGenerator())->generate('tool-kid')],
        );
    }

    public function testProducesARealJwtVerifiableWithTheToolsOwnJwks(): void
    {
        $registration = $this->registration();
        $response = new LtiDeepLinkingResponse(
            $registration,
            self::DEPLOYMENT_ID,
            self::ISSUER,
            [new LinkContentItem('https://example.com')],
        );

        $jwt = $response->toJwt();

        $jwks = (new JwksBuilder())->build($registration);
        $keySet = JWK::parseKeySet($jwks, 'RS256');
        $claims = JWT::decode($jwt, $keySet);

        self::assertSame(self::CLIENT_ID, $claims->iss);
        self::assertSame(self::ISSUER, $claims->aud);
        self::assertSame('LtiDeepLinkingResponse', $claims->{ClaimUris::MESSAGE_TYPE});
        self::assertSame('1.3.0', $claims->{ClaimUris::VERSION});
        self::assertSame(self::DEPLOYMENT_ID, $claims->{ClaimUris::DEPLOYMENT_ID});
    }

    public function testIncludesAllContentItemsInOrder(): void
    {
        $registration = $this->registration();
        $response = new LtiDeepLinkingResponse(
            $registration,
            self::DEPLOYMENT_ID,
            self::ISSUER,
            [
                new LtiResourceLinkContentItem(url: 'https://tool.example.com/1', title: 'First'),
                new LinkContentItem('https://example.com/2', title: 'Second'),
            ],
        );

        $jwt = $response->toJwt();
        $jwks = (new JwksBuilder())->build($registration);
        $keySet = JWK::parseKeySet($jwks, 'RS256');
        $claims = JWT::decode($jwt, $keySet);

        $contentItems = $claims->{ClaimUris::CONTENT_ITEMS};
        self::assertCount(2, $contentItems);
        self::assertSame('ltiResourceLink', $contentItems[0]->type);
        self::assertSame('First', $contentItems[0]->title);
        self::assertSame('link', $contentItems[1]->type);
        self::assertSame('Second', $contentItems[1]->title);
    }

    public function testEchoesBackTheDataClaimWhenProvided(): void
    {
        $registration = $this->registration();
        $response = new LtiDeepLinkingResponse(
            $registration,
            self::DEPLOYMENT_ID,
            self::ISSUER,
            [new LinkContentItem('https://example.com')],
            data: 'opaque-data-value',
        );

        $jwt = $response->toJwt();
        $jwks = (new JwksBuilder())->build($registration);
        $keySet = JWK::parseKeySet($jwks, 'RS256');
        $claims = JWT::decode($jwt, $keySet);

        self::assertSame('opaque-data-value', $claims->{ClaimUris::DATA});
    }

    public function testOmitsTheDataClaimWhenNotProvided(): void
    {
        $registration = $this->registration();
        $response = new LtiDeepLinkingResponse(
            $registration,
            self::DEPLOYMENT_ID,
            self::ISSUER,
            [new LinkContentItem('https://example.com')],
        );

        $jwt = $response->toJwt();
        $jwks = (new JwksBuilder())->build($registration);
        $keySet = JWK::parseKeySet($jwks, 'RS256');
        $claims = JWT::decode($jwt, $keySet);

        self::assertFalse(property_exists($claims, ClaimUris::DATA));
    }
}
