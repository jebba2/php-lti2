<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Tests\Integration\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use PhpLti\Lti1p3\Exception\ServiceException;
use PhpLti\Lti1p3\Message\Claims\AgsEndpoint;
use PhpLti\Lti1p3\Registration\Registration;
use PhpLti\Lti1p3\Registration\ToolKeyPair;
use PhpLti\Lti1p3\Services\AccessTokenService;
use PhpLti\Lti1p3\Services\Ags\ActivityProgress;
use PhpLti\Lti1p3\Services\Ags\AssignmentsGradesService;
use PhpLti\Lti1p3\Services\Ags\GradingProgress;
use PhpLti\Lti1p3\Services\Ags\LineItem;
use PhpLti\Lti1p3\Services\Ags\Score;
use PhpLti\Lti1p3\Security\Jwt\KeyPairGenerator;
use PhpLti\Lti1p3\Tests\Support\ArrayCache;
use PhpLti\Lti1p3\Tests\Support\FixturePlatformServer;
use PHPUnit\Framework\TestCase;

final class AssignmentsGradesServiceTest extends TestCase
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

    private function endpoint(): AgsEndpoint
    {
        $endpoint = AgsEndpoint::fromClaimValue([
            'scope' => ['https://purl.imsglobal.org/spec/lti-ags/scope/lineitem'],
            'lineitems' => $this->server()->baseUrl() . '/ags/lineitems',
        ]);
        self::assertNotNull($endpoint);

        return $endpoint;
    }

    private function service(): AssignmentsGradesService
    {
        $client = new Client();
        $factory = new HttpFactory();
        $accessTokenService = new AccessTokenService($client, $factory, $factory, new ArrayCache());

        return new AssignmentsGradesService($client, $factory, $factory, $accessTokenService);
    }

    private function queueTokenResponse(): void
    {
        $body = json_encode(['access_token' => 'access-token-1', 'expires_in' => 3600], JSON_THROW_ON_ERROR);
        $this->server()->queueResponse('POST', '/token', 200, [], $body);
    }

    public function testCreatesALineItemAndReturnsItWithTheAssignedId(): void
    {
        $this->queueTokenResponse();
        $lineItemUrl = $this->server()->baseUrl() . '/ags/lineitems/1';
        $created = ['id' => $lineItemUrl, 'scoreMaximum' => 100.0, 'label' => 'Assignment 1'];
        $this->server()->queueResponse('POST', '/ags/lineitems', 201, [], json_encode($created, JSON_THROW_ON_ERROR));

        $result = $this->service()->createLineItem(
            $this->registration(),
            $this->endpoint(),
            new LineItem(null, 100.0, 'Assignment 1', null, null, null),
        );

        self::assertSame($lineItemUrl, $result->id);
        self::assertSame(100.0, $result->scoreMaximum);
    }

    public function testSendsTheAccessTokenAsABearerHeaderOnTheCreateRequest(): void
    {
        $this->queueTokenResponse();
        $created = ['id' => 'x', 'scoreMaximum' => 100.0, 'label' => 'Assignment 1'];
        $this->server()->queueResponse('POST', '/ags/lineitems', 201, [], json_encode($created, JSON_THROW_ON_ERROR));

        $this->service()->createLineItem(
            $this->registration(),
            $this->endpoint(),
            new LineItem(null, 100.0, 'Assignment 1', null, null, null),
        );

        $requests = $this->server()->receivedRequests('POST', '/ags/lineitems');
        self::assertSame('Bearer access-token-1', $requests[0]['headers']['Authorization']);
    }

    public function testListsLineItems(): void
    {
        $this->queueTokenResponse();
        $body = json_encode([
            ['id' => 'a', 'scoreMaximum' => 10.0, 'label' => 'A'],
            ['id' => 'b', 'scoreMaximum' => 20.0, 'label' => 'B'],
        ], JSON_THROW_ON_ERROR);
        $this->server()->queueResponse('GET', '/ags/lineitems', 200, [], $body);

        $lineItems = $this->service()->listLineItems($this->registration(), $this->endpoint());

        self::assertCount(2, $lineItems);
        self::assertSame('a', $lineItems[0]->id);
        self::assertSame('b', $lineItems[1]->id);
    }

    public function testGetsALineItemByUrl(): void
    {
        $this->queueTokenResponse();
        $lineItemUrl = $this->server()->baseUrl() . '/ags/lineitems/1';
        $data = ['id' => $lineItemUrl, 'scoreMaximum' => 100.0, 'label' => 'Assignment 1'];
        $this->server()->queueResponse('GET', '/ags/lineitems/1', 200, [], json_encode($data, JSON_THROW_ON_ERROR));

        $lineItem = $this->service()->getLineItem($this->registration(), $lineItemUrl);

        self::assertSame($lineItemUrl, $lineItem->id);
    }

    public function testUpdatesALineItem(): void
    {
        $this->queueTokenResponse();
        $lineItemUrl = $this->server()->baseUrl() . '/ags/lineitems/1';
        $updated = ['id' => $lineItemUrl, 'scoreMaximum' => 50.0, 'label' => 'Renamed'];
        $this->server()->queueResponse('PUT', '/ags/lineitems/1', 200, [], json_encode($updated, JSON_THROW_ON_ERROR));

        $result = $this->service()->updateLineItem(
            $this->registration(),
            $lineItemUrl,
            new LineItem($lineItemUrl, 50.0, 'Renamed', null, null, null),
        );

        self::assertSame('Renamed', $result->label);
        self::assertSame(50.0, $result->scoreMaximum);
    }

    public function testDeletesALineItem(): void
    {
        $this->queueTokenResponse();
        $lineItemUrl = $this->server()->baseUrl() . '/ags/lineitems/1';
        $this->server()->queueResponse('DELETE', '/ags/lineitems/1', 204, [], '');

        $this->service()->deleteLineItem($this->registration(), $lineItemUrl);

        self::assertCount(1, $this->server()->receivedRequests('DELETE', '/ags/lineitems/1'));
    }

    public function testPublishesAScoreWithCorrectBody(): void
    {
        $this->queueTokenResponse();
        $lineItemUrl = $this->server()->baseUrl() . '/ags/lineitems/1';
        $this->server()->queueResponse('POST', '/ags/lineitems/1/scores', 200, [], '{}');

        $score = new Score(
            'user-1',
            ActivityProgress::Completed,
            GradingProgress::FullyGraded,
            scoreGiven: 8.5,
            scoreMaximum: 10.0,
        );
        $this->service()->publishScore($this->registration(), $lineItemUrl, $score);

        $requests = $this->server()->receivedRequests('POST', '/ags/lineitems/1/scores');
        self::assertCount(1, $requests);
        $sent = json_decode($requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($sent);
        self::assertSame('user-1', $sent['userId']);
        self::assertSame(8.5, $sent['scoreGiven']);
        self::assertSame('Completed', $sent['activityProgress']);
        self::assertSame('FullyGraded', $sent['gradingProgress']);
    }

    public function testListsResults(): void
    {
        $this->queueTokenResponse();
        $lineItemUrl = $this->server()->baseUrl() . '/ags/lineitems/1';
        $resultData = [
            'id' => 'r1',
            'scoreOf' => $lineItemUrl,
            'userId' => 'user-1',
            'resultScore' => 8.5,
            'resultMaximum' => 10.0,
        ];
        $body = json_encode([$resultData], JSON_THROW_ON_ERROR);
        $this->server()->queueResponse('GET', '/ags/lineitems/1/results', 200, [], $body);

        $results = $this->service()->listResults($this->registration(), $lineItemUrl);

        self::assertCount(1, $results);
        self::assertSame('user-1', $results[0]->userId);
        self::assertSame(8.5, $results[0]->resultScore);
    }

    public function testThrowsServiceExceptionOnNonSuccessfulStatus(): void
    {
        $this->queueTokenResponse();
        $this->server()->queueResponse('POST', '/ags/lineitems', 403, [], '{"error":"forbidden"}');

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('HTTP status 403');

        $this->service()->createLineItem(
            $this->registration(),
            $this->endpoint(),
            new LineItem(null, 100.0, 'Assignment 1', null, null, null),
        );
    }
}
