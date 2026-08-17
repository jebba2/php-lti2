<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Unit\Support;

use PhpLti\Lti1p3\Registration\Registration;
use PhpLti\Lti1p3\Registration\ToolKeyPair;
use PhpLti\Lti1p3\Tests\Support\InMemoryRegistrationRepository;
use PHPUnit\Framework\TestCase;

final class InMemoryRegistrationRepositoryTest extends TestCase
{
    private function registration(string $issuer, string $clientId, string $deploymentId = 'deployment-1'): Registration
    {
        return new Registration(
            $issuer,
            $clientId,
            [$deploymentId],
            $issuer . '/auth',
            $issuer . '/token',
            $issuer . '/jwks',
            [new ToolKeyPair('kid-1', 'priv', 'pub')],
        );
    }

    public function testFindsByIssuerAloneWhenOnlyOneRegistrationMatches(): void
    {
        $repository = new InMemoryRegistrationRepository();
        $registration = $this->registration('https://one.example.com', 'client-1');
        $repository->add($registration);

        self::assertSame($registration, $repository->findForLoginInitiation('https://one.example.com', null));
    }

    public function testReturnsNullForAmbiguousIssuerWithoutClientId(): void
    {
        $repository = new InMemoryRegistrationRepository();
        $repository->add($this->registration('https://shared.example.com', 'client-1'));
        $repository->add($this->registration('https://shared.example.com', 'client-2'));

        self::assertNull($repository->findForLoginInitiation('https://shared.example.com', null));
    }

    public function testFindsByIssuerAndClientIdWhenBothGiven(): void
    {
        $repository = new InMemoryRegistrationRepository();
        $repository->add($this->registration('https://shared.example.com', 'client-1'));
        $second = $this->registration('https://shared.example.com', 'client-2');
        $repository->add($second);

        self::assertSame($second, $repository->findForLoginInitiation('https://shared.example.com', 'client-2'));
    }

    public function testReturnsNullWhenNoRegistrationMatchesTheIssuer(): void
    {
        $repository = new InMemoryRegistrationRepository();

        self::assertNull($repository->findForLoginInitiation('https://unknown.example.com', null));
    }

    public function testFindForLaunchRequiresIssuerClientIdAndDeploymentToAllMatch(): void
    {
        $repository = new InMemoryRegistrationRepository();
        $registration = $this->registration('https://one.example.com', 'client-1', 'deployment-1');
        $repository->add($registration);

        self::assertSame(
            $registration,
            $repository->findForLaunch('https://one.example.com', 'client-1', 'deployment-1'),
        );
        self::assertNull($repository->findForLaunch('https://one.example.com', 'client-1', 'wrong-deployment'));
        self::assertNull($repository->findForLaunch('https://one.example.com', 'wrong-client', 'deployment-1'));
        self::assertNull($repository->findForLaunch('https://wrong.example.com', 'client-1', 'deployment-1'));
    }
}
