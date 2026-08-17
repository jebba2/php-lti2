<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Services\Nrps;

use PhpLti\Lti1p3\Exception\ServiceException;
use PhpLti\Lti1p3\Message\Claims\NrpsEndpoint;
use PhpLti\Lti1p3\Registration\Registration;
use PhpLti\Lti1p3\Services\AccessTokenService;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Names and Role Provisioning Service 2.0: fetches the roster for the
 * launch's context, following `Link: rel="next"` pagination.
 */
final class NamesRoleService
{
    private const SCOPE_READONLY = 'https://purl.imsglobal.org/spec/lti-nrps/scope/contextmembership.readonly';
    private const CONTENT_TYPE_MEMBERSHIP_CONTAINER = 'application/vnd.ims.lti-nrps.v2.membershipcontainer+json';

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly AccessTokenService $accessTokenService,
    ) {
    }

    /**
     * @return list<Member>
     */
    public function getMembers(Registration $registration, NrpsEndpoint $endpoint): array
    {
        $members = [];
        $url = $endpoint->contextMembershipsUrl;

        while ($url !== null) {
            $response = $this->fetchPage($registration, $url);
            $decoded = $this->decodeJsonBody($response, $url);

            if (!is_array($decoded) || !isset($decoded['members']) || !is_array($decoded['members'])) {
                throw new ServiceException(sprintf('NRPS response from "%s" did not contain a "members" array.', $url));
            }

            foreach ($decoded['members'] as $entry) {
                $members[] = Member::fromResponseData($entry);
            }

            $url = $this->nextPageUrl($response);
        }

        return $members;
    }

    private function fetchPage(Registration $registration, string $url): ResponseInterface
    {
        $accessToken = $this->accessTokenService->getAccessToken($registration, [self::SCOPE_READONLY]);

        $request = $this->requestFactory
            ->createRequest('GET', $url)
            ->withHeader('Authorization', 'Bearer ' . $accessToken)
            ->withHeader('Accept', self::CONTENT_TYPE_MEMBERSHIP_CONTAINER);

        $response = $this->httpClient->sendRequest($request);

        if ($response->getStatusCode() !== 200) {
            throw new ServiceException(sprintf(
                'NRPS request to "%s" failed with HTTP status %d.',
                $url,
                $response->getStatusCode(),
            ));
        }

        return $response;
    }

    private function nextPageUrl(ResponseInterface $response): ?string
    {
        $linkHeader = $response->getHeaderLine('Link');
        if ($linkHeader === '') {
            return null;
        }

        if (preg_match('/<([^>]+)>\s*;\s*rel="next"/', $linkHeader, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function decodeJsonBody(ResponseInterface $response, string $url): mixed
    {
        try {
            return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $message = sprintf('NRPS response from "%s" was not valid JSON.', $url);

            throw new ServiceException($message, previous: $exception);
        }
    }
}
