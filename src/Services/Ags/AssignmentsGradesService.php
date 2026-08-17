<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Services\Ags;

use PhpLti\Lti1p3\Exception\ServiceException;
use PhpLti\Lti1p3\Message\Claims\AgsEndpoint;
use PhpLti\Lti1p3\Registration\Registration;
use PhpLti\Lti1p3\Services\AccessTokenService;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Assignment and Grades Service 2.0: line item CRUD, score publish, result
 * read. Line item listing is a single-page fetch (no Link-header
 * pagination) — matching the scope of what a tool typically needs
 * (its own resource link's line item(s)), not a full paginated dump of
 * every line item in a course.
 */
final class AssignmentsGradesService
{
    private const SCOPE_LINE_ITEM = 'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem';
    private const SCOPE_RESULT_READONLY = 'https://purl.imsglobal.org/spec/lti-ags/scope/result.readonly';
    private const SCOPE_SCORE = 'https://purl.imsglobal.org/spec/lti-ags/scope/score';

    private const CONTENT_TYPE_LINE_ITEM = 'application/vnd.ims.lis.v2.lineitem+json';
    private const CONTENT_TYPE_LINE_ITEM_CONTAINER = 'application/vnd.ims.lis.v2.lineitemcontainer+json';
    private const CONTENT_TYPE_SCORE = 'application/vnd.ims.lis.v1.score+json';
    private const CONTENT_TYPE_RESULT_CONTAINER = 'application/vnd.ims.lis.v2.resultcontainer+json';

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly AccessTokenService $accessTokenService,
    ) {
    }

    /**
     * @return list<LineItem>
     */
    public function listLineItems(Registration $registration, AgsEndpoint $endpoint): array
    {
        $response = $this->request(
            $registration,
            'GET',
            $endpoint->lineItemsUrl,
            [self::SCOPE_LINE_ITEM],
            self::CONTENT_TYPE_LINE_ITEM_CONTAINER,
        );

        $decoded = $this->decodeJsonBody($response, $endpoint->lineItemsUrl);
        if (!is_array($decoded)) {
            throw new ServiceException(sprintf(
                'AGS line items response from "%s" was not a JSON array.',
                $endpoint->lineItemsUrl,
            ));
        }

        return array_values(array_map(
            static fn (mixed $entry): LineItem => LineItem::fromResponseData($entry),
            $decoded,
        ));
    }

    public function createLineItem(Registration $registration, AgsEndpoint $endpoint, LineItem $lineItem): LineItem
    {
        $response = $this->request(
            $registration,
            'POST',
            $endpoint->lineItemsUrl,
            [self::SCOPE_LINE_ITEM],
            self::CONTENT_TYPE_LINE_ITEM,
            $lineItem->toArray(),
        );

        return LineItem::fromResponseData($this->decodeJsonBody($response, $endpoint->lineItemsUrl));
    }

    public function getLineItem(Registration $registration, string $lineItemUrl): LineItem
    {
        $response = $this->request(
            $registration,
            'GET',
            $lineItemUrl,
            [self::SCOPE_LINE_ITEM],
            self::CONTENT_TYPE_LINE_ITEM,
        );

        return LineItem::fromResponseData($this->decodeJsonBody($response, $lineItemUrl));
    }

    public function updateLineItem(Registration $registration, string $lineItemUrl, LineItem $lineItem): LineItem
    {
        $response = $this->request(
            $registration,
            'PUT',
            $lineItemUrl,
            [self::SCOPE_LINE_ITEM],
            self::CONTENT_TYPE_LINE_ITEM,
            $lineItem->toArray(),
        );

        return LineItem::fromResponseData($this->decodeJsonBody($response, $lineItemUrl));
    }

    public function deleteLineItem(Registration $registration, string $lineItemUrl): void
    {
        $this->request($registration, 'DELETE', $lineItemUrl, [self::SCOPE_LINE_ITEM], null);
    }

    public function publishScore(Registration $registration, string $lineItemUrl, Score $score): void
    {
        $this->request(
            $registration,
            'POST',
            rtrim($lineItemUrl, '/') . '/scores',
            [self::SCOPE_SCORE],
            self::CONTENT_TYPE_SCORE,
            $score->toArray(),
        );
    }

    /**
     * @return list<Result>
     */
    public function listResults(Registration $registration, string $lineItemUrl): array
    {
        $resultsUrl = rtrim($lineItemUrl, '/') . '/results';
        $response = $this->request(
            $registration,
            'GET',
            $resultsUrl,
            [self::SCOPE_RESULT_READONLY],
            self::CONTENT_TYPE_RESULT_CONTAINER,
        );

        $decoded = $this->decodeJsonBody($response, $resultsUrl);
        if (!is_array($decoded)) {
            throw new ServiceException(sprintf('AGS results response from "%s" was not a JSON array.', $resultsUrl));
        }

        return array_values(array_map(
            static fn (mixed $entry): Result => Result::fromResponseData($entry),
            $decoded,
        ));
    }

    /**
     * @param list<string> $scopes
     * @param array<string, mixed>|null $jsonBody
     */
    private function request(
        Registration $registration,
        string $method,
        string $url,
        array $scopes,
        ?string $contentType,
        ?array $jsonBody = null,
    ): ResponseInterface {
        $accessToken = $this->accessTokenService->getAccessToken($registration, $scopes);

        $request = $this->requestFactory
            ->createRequest($method, $url)
            ->withHeader('Authorization', 'Bearer ' . $accessToken)
            ->withHeader('Accept', $contentType ?? 'application/json');

        if ($jsonBody !== null) {
            $request = $request
                ->withHeader('Content-Type', $contentType ?? 'application/json')
                ->withBody($this->streamFactory->createStream(
                    (string) json_encode($jsonBody, JSON_THROW_ON_ERROR),
                ));
        }

        $response = $this->httpClient->sendRequest($request);

        if ($response->getStatusCode() >= 300) {
            throw new ServiceException(sprintf(
                'AGS request %s "%s" failed with HTTP status %d.',
                $method,
                $url,
                $response->getStatusCode(),
            ));
        }

        return $response;
    }

    private function decodeJsonBody(ResponseInterface $response, string $url): mixed
    {
        $body = (string) $response->getBody();
        if ($body === '') {
            return null;
        }

        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ServiceException(sprintf('Response from "%s" was not valid JSON.', $url), previous: $exception);
        }
    }
}
