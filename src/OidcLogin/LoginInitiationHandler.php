<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\OidcLogin;

use PhpLti\Lti1p3\Cache\CacheKeyBuilder;
use PhpLti\Lti1p3\Exception\InvalidLoginInitiationException;
use PhpLti\Lti1p3\Exception\RegistrationNotFoundException;
use PhpLti\Lti1p3\Registration\Registration;
use PhpLti\Lti1p3\Registration\RegistrationRepositoryInterface;
use PhpLti\Lti1p3\Security\UrlSecurity;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Handles the OIDC third-party initiated login request the platform sends
 * to the tool's login-initiation endpoint (GET query params or POST
 * form-encoded body), and builds the redirect back to the platform's
 * authentication endpoint.
 *
 * Mints and caches a `state` + `nonce` pair (plus the target_link_uri and
 * deployment_id the platform sent, if any) keyed by `state`, so
 * LaunchValidator can later verify the returned id_token's claims match
 * what was recorded here — closing the parameter-substitution gap between
 * the two legs of the OIDC flow.
 */
final class LoginInitiationHandler
{
    private const DEFAULT_STATE_TTL_SECONDS = 300;

    public function __construct(
        private readonly RegistrationRepositoryInterface $registrations,
        private readonly CacheInterface $stateCache,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly int $stateTtlSeconds = self::DEFAULT_STATE_TTL_SECONDS,
    ) {
    }

    public function handle(ServerRequestInterface $request, string $redirectUri): ResponseInterface
    {
        $params = $this->extractParams($request);

        if (!UrlSecurity::isSecure($params['target_link_uri'])) {
            throw new InvalidLoginInitiationException(
                'The "target_link_uri" must use https (or loopback for local dev/testing).',
            );
        }

        if (!UrlSecurity::isSecure($redirectUri)) {
            throw new InvalidLoginInitiationException(
                'The redirect_uri must use https (or loopback for local dev/testing).',
            );
        }

        $registration = $this->registrations->findForLoginInitiation($params['iss'], $params['client_id']);
        if ($registration === null) {
            throw new RegistrationNotFoundException(sprintf(
                'No registration found for issuer "%s"%s.',
                $params['iss'],
                $params['client_id'] !== null ? sprintf(' and client_id "%s"', $params['client_id']) : '',
            ));
        }

        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));

        $this->stateCache->set(
            CacheKeyBuilder::build('login-state', $state),
            [
                'nonce' => $nonce,
                'target_link_uri' => $params['target_link_uri'],
                'deployment_id' => $params['lti_deployment_id'],
                'issuer' => $params['iss'],
                'client_id' => $registration->clientId,
            ],
            $this->stateTtlSeconds,
        );

        $authenticationRequestUrl = $this->buildAuthenticationRequestUrl(
            $registration,
            $redirectUri,
            $params['login_hint'],
            $state,
            $nonce,
            $params['lti_message_hint'],
        );

        return $this->responseFactory
            ->createResponse(302)
            ->withHeader('Location', $authenticationRequestUrl);
    }

    /**
     * @return array{
     *     iss: string,
     *     login_hint: string,
     *     target_link_uri: string,
     *     client_id: ?string,
     *     lti_deployment_id: ?string,
     *     lti_message_hint: ?string,
     * }
     */
    private function extractParams(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        $source = array_merge($request->getQueryParams(), is_array($body) ? $body : []);

        return [
            'iss' => $this->requireStringParam($source, 'iss'),
            'login_hint' => $this->requireStringParam($source, 'login_hint'),
            'target_link_uri' => $this->requireStringParam($source, 'target_link_uri'),
            'client_id' => $this->optionalStringParam($source, 'client_id'),
            'lti_deployment_id' => $this->optionalStringParam($source, 'lti_deployment_id'),
            'lti_message_hint' => $this->optionalStringParam($source, 'lti_message_hint'),
        ];
    }

    /**
     * @param array<string, mixed> $source
     */
    private function requireStringParam(array $source, string $name): string
    {
        $value = $source[$name] ?? null;

        if (!is_string($value) || $value === '') {
            throw new InvalidLoginInitiationException(sprintf('Missing or invalid required parameter "%s".', $name));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function optionalStringParam(array $source, string $name): ?string
    {
        $value = $source[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function buildAuthenticationRequestUrl(
        Registration $registration,
        string $redirectUri,
        string $loginHint,
        string $state,
        string $nonce,
        ?string $messageHint,
    ): string {
        $params = [
            'scope' => 'openid',
            'response_type' => 'id_token',
            'client_id' => $registration->clientId,
            'redirect_uri' => $redirectUri,
            'login_hint' => $loginHint,
            'state' => $state,
            'response_mode' => 'form_post',
            'nonce' => $nonce,
            'prompt' => 'none',
        ];

        if ($messageHint !== null) {
            $params['lti_message_hint'] = $messageHint;
        }

        $separator = str_contains($registration->platformAuthenticationLoginUrl, '?') ? '&' : '?';

        return $registration->platformAuthenticationLoginUrl . $separator . http_build_query($params);
    }
}
