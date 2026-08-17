<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Unit\Registration;

use PhpLti\Lti1p3\Exception\InvalidRegistrationException;
use PhpLti\Lti1p3\Registration\Registration;
use PhpLti\Lti1p3\Registration\ToolKeyPair;
use PHPUnit\Framework\TestCase;

final class RegistrationTest extends TestCase
{
    private function keyPair(string $kid = 'kid-1', bool $activeForSigning = true): ToolKeyPair
    {
        return new ToolKeyPair($kid, 'priv-' . $kid, 'pub-' . $kid, $activeForSigning);
    }

    /**
     * @param list<string> $deploymentIds
     * @param list<ToolKeyPair> $toolKeyPairs
     */
    private function makeRegistration(
        string $issuer = 'https://example.brightspace.com',
        string $clientId = 'client-1',
        array $deploymentIds = ['deployment-1'],
        string $loginUrl = 'https://example.brightspace.com/d2l/lti/authenticate',
        string $tokenUrl = 'https://example.brightspace.com/core/connect/token',
        string $jwksUrl = 'https://example.brightspace.com/d2l/.well-known/jwks',
        ?array $toolKeyPairs = null,
    ): Registration {
        return new Registration(
            $issuer,
            $clientId,
            $deploymentIds,
            $loginUrl,
            $tokenUrl,
            $jwksUrl,
            $toolKeyPairs ?? [$this->keyPair()],
        );
    }

    public function testConstructsWithValidValues(): void
    {
        $registration = $this->makeRegistration();

        self::assertSame('https://example.brightspace.com', $registration->issuer);
        self::assertSame('client-1', $registration->clientId);
        self::assertSame(['deployment-1'], $registration->deploymentIds);
        $loginUrl = 'https://example.brightspace.com/d2l/lti/authenticate';
        $tokenUrl = 'https://example.brightspace.com/core/connect/token';
        self::assertSame($loginUrl, $registration->platformAuthenticationLoginUrl);
        self::assertSame($tokenUrl, $registration->platformAuthenticationTokenUrl);
        self::assertSame('https://example.brightspace.com/d2l/.well-known/jwks', $registration->platformJwksUrl);
        self::assertCount(1, $registration->toolKeyPairs);
    }

    public function testRejectsEmptyIssuer(): void
    {
        $this->expectException(InvalidRegistrationException::class);
        $this->expectExceptionMessage('issuer must not be empty');

        $this->makeRegistration(issuer: '');
    }

    public function testRejectsEmptyClientId(): void
    {
        $this->expectException(InvalidRegistrationException::class);
        $this->expectExceptionMessage('clientId must not be empty');

        $this->makeRegistration(clientId: '');
    }

    public function testRejectsEmptyDeploymentIds(): void
    {
        $this->expectException(InvalidRegistrationException::class);
        $this->expectExceptionMessage('at least one deployment id');

        $this->makeRegistration(deploymentIds: []);
    }

    public function testRejectsBlankDeploymentId(): void
    {
        $this->expectException(InvalidRegistrationException::class);
        $this->expectExceptionMessage('deployment ids must be non-empty strings');

        $this->makeRegistration(deploymentIds: ['deployment-1', '   ']);
    }

    public function testRejectsEmptyLoginUrl(): void
    {
        $this->expectException(InvalidRegistrationException::class);
        $this->expectExceptionMessage('platformAuthenticationLoginUrl must not be empty');

        $this->makeRegistration(loginUrl: '');
    }

    public function testRejectsEmptyTokenUrl(): void
    {
        $this->expectException(InvalidRegistrationException::class);
        $this->expectExceptionMessage('platformAuthenticationTokenUrl must not be empty');

        $this->makeRegistration(tokenUrl: '');
    }

    public function testRejectsEmptyJwksUrl(): void
    {
        $this->expectException(InvalidRegistrationException::class);
        $this->expectExceptionMessage('platformJwksUrl must not be empty');

        $this->makeRegistration(jwksUrl: '');
    }

    public function testRejectsAPlainHttpLoginUrlToARemoteHost(): void
    {
        $this->expectException(InvalidRegistrationException::class);
        $this->expectExceptionMessage('platformAuthenticationLoginUrl must use https');

        $this->makeRegistration(loginUrl: 'http://example.brightspace.com/d2l/lti/authenticate');
    }

    public function testRejectsAPlainHttpTokenUrlToARemoteHost(): void
    {
        $this->expectException(InvalidRegistrationException::class);
        $this->expectExceptionMessage('platformAuthenticationTokenUrl must use https');

        $this->makeRegistration(tokenUrl: 'http://example.brightspace.com/core/connect/token');
    }

    public function testRejectsAPlainHttpJwksUrlToARemoteHost(): void
    {
        $this->expectException(InvalidRegistrationException::class);
        $this->expectExceptionMessage('platformJwksUrl must use https');

        $this->makeRegistration(jwksUrl: 'http://example.brightspace.com/d2l/.well-known/jwks');
    }

    public function testAllowsPlainHttpToLoopbackForLocalDevAndTesting(): void
    {
        $registration = $this->makeRegistration(
            loginUrl: 'http://127.0.0.1:8080/auth',
            tokenUrl: 'http://127.0.0.1:8080/token',
            jwksUrl: 'http://127.0.0.1:8080/jwks',
        );

        self::assertSame('http://127.0.0.1:8080/jwks', $registration->platformJwksUrl);
    }

    public function testRejectsEmptyToolKeyPairs(): void
    {
        $this->expectException(InvalidRegistrationException::class);
        $this->expectExceptionMessage('at least one tool key pair');

        $this->makeRegistration(toolKeyPairs: []);
    }

    public function testRejectsZeroActiveSigningKeys(): void
    {
        $this->expectException(InvalidRegistrationException::class);
        $this->expectExceptionMessage('exactly one active signing key, 0 given');

        $this->makeRegistration(toolKeyPairs: [$this->keyPair('kid-1', false)]);
    }

    public function testRejectsMultipleActiveSigningKeys(): void
    {
        $this->expectException(InvalidRegistrationException::class);
        $this->expectExceptionMessage('exactly one active signing key, 2 given');

        $this->makeRegistration(toolKeyPairs: [
            $this->keyPair('kid-1', true),
            $this->keyPair('kid-2', true),
        ]);
    }

    public function testAllowsRotatedInactiveKeyAlongsideActiveKey(): void
    {
        $registration = $this->makeRegistration(toolKeyPairs: [
            $this->keyPair('old-kid', false),
            $this->keyPair('new-kid', true),
        ]);

        self::assertCount(2, $registration->toolKeyPairs);
    }

    public function testHasDeploymentReturnsTrueForKnownDeployment(): void
    {
        $registration = $this->makeRegistration(deploymentIds: ['dep-a', 'dep-b']);

        self::assertTrue($registration->hasDeployment('dep-a'));
        self::assertTrue($registration->hasDeployment('dep-b'));
    }

    public function testHasDeploymentReturnsFalseForUnknownDeployment(): void
    {
        $registration = $this->makeRegistration(deploymentIds: ['dep-a']);

        self::assertFalse($registration->hasDeployment('dep-z'));
    }

    public function testActiveSigningKeyReturnsTheActiveKey(): void
    {
        $registration = $this->makeRegistration(toolKeyPairs: [
            $this->keyPair('old-kid', false),
            $this->keyPair('new-kid', true),
        ]);

        self::assertSame('new-kid', $registration->activeSigningKey()->kid);
    }

    public function testFindKeyPairByKidFindsActiveAndInactiveKeys(): void
    {
        $registration = $this->makeRegistration(toolKeyPairs: [
            $this->keyPair('old-kid', false),
            $this->keyPair('new-kid', true),
        ]);

        self::assertSame('old-kid', $registration->findKeyPairByKid('old-kid')?->kid);
        self::assertSame('new-kid', $registration->findKeyPairByKid('new-kid')?->kid);
    }

    public function testFindKeyPairByKidReturnsNullForUnknownKid(): void
    {
        $registration = $this->makeRegistration();

        self::assertNull($registration->findKeyPairByKid('unknown'));
    }
}
