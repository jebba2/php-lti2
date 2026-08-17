<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Integration\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use PhpLti\Lti1p3\Exception\ServiceException;
use PhpLti\Lti1p3\Message\Claims\NrpsEndpoint;
use PhpLti\Lti1p3\Registration\Registration;
use PhpLti\Lti1p3\Security\Jwt\KeyPairGenerator;
use PhpLti\Lti1p3\Services\AccessTokenService;
use PhpLti\Lti1p3\Services\Nrps\NamesRoleService;
use PhpLti\Lti1p3\Tests\Support\ArrayCache;
use PhpLti\Lti1p3\Tests\Support\FixturePlatformServer;
use PHPUnit\Framework\TestCase;

final class NamesRoleServiceTest extends TestCase
{
    private const ISSUER = 'https://example.brightspace.com';
    private const CLIENT_ID = 'client-1';

    private ?FixturePlatformServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    private function server(): FixturePlatformServer
    {
        return $this->server ??= FixturePlatformServer::start();
    }

    private function registration(): Registration
    {
        return new Registration(
            self::ISSUER,
            self::CLIENT_ID,
            ['deployment-1'],
            self::ISSUER . '/d2l/lti/authenticate',
            $this->server()->baseUrl() . '/token',
            self::ISSUER . '/d2l/.well-known/jwks',
            [(new KeyPairGenerator())->generate('tool-kid')],
        );
    }

    private function endpoint(): NrpsEndpoint
    {
        $endpoint = NrpsEndpoint::fromClaimValue([
            'context_memberships_url' => $this->server()->baseUrl() . '/nrps/memberships',
            'service_versions' => ['2.0'],
        ]);
        self::assertNotNull($endpoint);

        return $endpoint;
    }

    private function service(): NamesRoleService
    {
        $client = new Client();
        $factory = new HttpFactory();
        $accessTokenService = new AccessTokenService($client, $factory, $factory, new ArrayCache());

        return new NamesRoleService($client, $factory, $accessTokenService);
    }

    private function queueTokenResponse(): void
    {
        $body = json_encode(['access_token' => 'access-token-1', 'expires_in' => 3600], JSON_THROW_ON_ERROR);
        $this->server()->queueResponse('POST', '/token', 200, [], $body);
    }

    public function testFetchesTheRoster(): void
    {
        $this->queueTokenResponse();
        $body = json_encode([
            'id' => $this->server()->baseUrl() . '/nrps/memberships',
            'members' => [
                ['status' => 'Active', 'name' => 'Ada Lovelace', 'user_id' => 'user-1', 'roles' => []],
                ['status' => 'Active', 'name' => 'Alan Turing', 'user_id' => 'user-2', 'roles' => []],
            ],
        ], JSON_THROW_ON_ERROR);
        $this->server()->queueResponse('GET', '/nrps/memberships', 200, [], $body);

        $members = $this->service()->getMembers($this->registration(), $this->endpoint());

        self::assertCount(2, $members);
        self::assertSame('user-1', $members[0]->userId);
        self::assertSame('user-2', $members[1]->userId);
    }

    public function testSendsTheAccessTokenAsABearerHeader(): void
    {
        $this->queueTokenResponse();
        $body = json_encode(['members' => []], JSON_THROW_ON_ERROR);
        $this->server()->queueResponse('GET', '/nrps/memberships', 200, [], $body);

        $this->service()->getMembers($this->registration(), $this->endpoint());

        $requests = $this->server()->receivedRequests('GET', '/nrps/memberships');
        self::assertSame('Bearer access-token-1', $requests[0]['headers']['Authorization']);
    }

    public function testFollowsLinkHeaderPaginationAcrossMultiplePages(): void
    {
        $this->queueTokenResponse();
        $secondPageUrl = $this->server()->baseUrl() . '/nrps/memberships?page=2';

        $firstPage = json_encode(['members' => [['user_id' => 'user-1', 'roles' => []]]], JSON_THROW_ON_ERROR);
        $this->server()->queueResponse(
            'GET',
            '/nrps/memberships',
            200,
            ['Link' => '<' . $secondPageUrl . '>; rel="next"'],
            $firstPage,
        );

        $secondPage = json_encode(['members' => [['user_id' => 'user-2', 'roles' => []]]], JSON_THROW_ON_ERROR);
        $this->server()->queueResponse('GET', '/nrps/memberships', 200, [], $secondPage);

        $members = $this->service()->getMembers($this->registration(), $this->endpoint());

        self::assertCount(2, $members);
        self::assertSame('user-1', $members[0]->userId);
        self::assertSame('user-2', $members[1]->userId);
    }

    public function testStopsWhenNoNextLinkIsPresent(): void
    {
        $this->queueTokenResponse();
        $body = json_encode(['members' => [['user_id' => 'user-1', 'roles' => []]]], JSON_THROW_ON_ERROR);
        $this->server()->queueResponse('GET', '/nrps/memberships', 200, [], $body);

        $this->service()->getMembers($this->registration(), $this->endpoint());

        self::assertCount(1, $this->server()->receivedRequests('GET', '/nrps/memberships'));
    }

    public function testThrowsWhenHttpStatusIsNotSuccessful(): void
    {
        $this->queueTokenResponse();
        $this->server()->queueResponse('GET', '/nrps/memberships', 403, [], '{"error":"forbidden"}');

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('HTTP status 403');

        $this->service()->getMembers($this->registration(), $this->endpoint());
    }

    public function testThrowsWhenResponseHasNoMembersArray(): void
    {
        $this->queueTokenResponse();
        $this->server()->queueResponse('GET', '/nrps/memberships', 200, [], '{}');

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('did not contain a "members" array');

        $this->service()->getMembers($this->registration(), $this->endpoint());
    }
}
