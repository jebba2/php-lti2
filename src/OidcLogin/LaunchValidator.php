<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\OidcLogin;

use PhpLti\Lti1p3\Cache\CacheKeyBuilder;
use PhpLti\Lti1p3\Exception\InvalidLaunchException;
use PhpLti\Lti1p3\Exception\InvalidLaunchReason;
use PhpLti\Lti1p3\Exception\InvalidLoginInitiationException;
use PhpLti\Lti1p3\Exception\RegistrationNotFoundException;
use PhpLti\Lti1p3\Message\ClaimUris;
use PhpLti\Lti1p3\Message\DeepLinking\LtiDeepLinkingRequest;
use PhpLti\Lti1p3\Message\LtiMessage;
use PhpLti\Lti1p3\Message\LtiResourceLinkRequest;
use PhpLti\Lti1p3\Registration\RegistrationRepositoryInterface;
use PhpLti\Lti1p3\Security\Jwt\JwtValidator;
use Psr\Http\Message\ServerRequestInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Validates the platform's authentication response (the POST back to the
 * tool's redirect_uri with `state` + `id_token`), and produces the typed
 * LtiMessage matching its `message_type` (LtiResourceLinkRequest or
 * LtiDeepLinkingRequest).
 *
 * Beyond JwtValidator's JWT-level checks (signature, iss, aud/azp, exp/iat,
 * nonce replay), this closes the parameter-substitution gap between the
 * two legs of the OIDC flow: the id_token's nonce/target_link_uri/
 * deployment_id must match what LoginInitiationHandler recorded for this
 * exact `state` — not just be individually well-formed.
 */
final class LaunchValidator
{
    public function __construct(
        private readonly RegistrationRepositoryInterface $registrations,
        private readonly CacheInterface $stateCache,
        private readonly JwtValidator $jwtValidator,
    ) {
    }

    public function validate(ServerRequestInterface $request): LtiMessage
    {
        $params = $this->extractParams($request);
        $stateRecord = $this->consumeLoginState($params['state']);

        $registration = $this->registrations->findForLoginInitiation(
            $stateRecord['issuer'],
            $stateRecord['client_id'],
        );
        if ($registration === null) {
            throw new RegistrationNotFoundException(sprintf(
                'No registration found for issuer "%s" and client_id "%s".',
                $stateRecord['issuer'],
                $stateRecord['client_id'],
            ));
        }

        $claims = $this->jwtValidator->validate($params['id_token'], $registration);

        $this->assertRoundtripBinding($claims, $stateRecord);

        $deploymentId = $claims[ClaimUris::DEPLOYMENT_ID] ?? null;
        if (!is_string($deploymentId) || !$registration->hasDeployment($deploymentId)) {
            throw new InvalidLaunchException(
                InvalidLaunchReason::InvalidDeploymentId,
                'The id_token "deployment_id" is not registered for this platform.',
            );
        }

        return $this->buildMessage($claims);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function buildMessage(array $claims): LtiMessage
    {
        $messageType = $claims[ClaimUris::MESSAGE_TYPE] ?? null;

        return match ($messageType) {
            LtiResourceLinkRequest::MESSAGE_TYPE => LtiResourceLinkRequest::fromClaims($claims),
            LtiDeepLinkingRequest::MESSAGE_TYPE => LtiDeepLinkingRequest::fromClaims($claims),
            default => throw new InvalidLaunchException(
                InvalidLaunchReason::UnexpectedMessageType,
                sprintf('Unsupported message_type "%s".', is_string($messageType) ? $messageType : 'null'),
            ),
        };
    }

    /**
     * @return array{state: string, id_token: string}
     */
    private function extractParams(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        $source = is_array($body) ? $body : [];

        $state = $source['state'] ?? null;
        $idToken = $source['id_token'] ?? null;

        if (!is_string($state) || $state === '') {
            throw new InvalidLoginInitiationException('Missing or invalid required parameter "state".');
        }

        if (!is_string($idToken) || $idToken === '') {
            throw new InvalidLoginInitiationException('Missing or invalid required parameter "id_token".');
        }

        return ['state' => $state, 'id_token' => $idToken];
    }

    /**
     * @return array{nonce: string, target_link_uri: string, deployment_id: ?string, issuer: string, client_id: string}
     */
    private function consumeLoginState(string $state): array
    {
        $cacheKey = CacheKeyBuilder::build('login-state', $state);
        $record = $this->stateCache->get($cacheKey);

        if (!is_array($record)) {
            throw new InvalidLaunchException(
                InvalidLaunchReason::InvalidState,
                'The "state" parameter is missing, expired, or unknown.',
            );
        }

        $this->stateCache->delete($cacheKey);

        /** @var array{nonce: string, target_link_uri: string, deployment_id: ?string, issuer: string, client_id: string} $record */
        return $record;
    }

    /**
     * @param array<string, mixed> $claims
     * @param array{
     *     nonce: string,
     *     target_link_uri: string,
     *     deployment_id: ?string,
     *     issuer: string,
     *     client_id: string,
     * } $stateRecord
     */
    private function assertRoundtripBinding(array $claims, array $stateRecord): void
    {
        $nonce = $claims['nonce'] ?? null;
        if ($nonce !== $stateRecord['nonce']) {
            throw new InvalidLaunchException(
                InvalidLaunchReason::InvalidState,
                'The id_token "nonce" does not match the one issued at login.',
            );
        }

        $targetLinkUri = $claims[ClaimUris::TARGET_LINK_URI] ?? null;
        if ($targetLinkUri !== $stateRecord['target_link_uri']) {
            throw new InvalidLaunchException(
                InvalidLaunchReason::InvalidState,
                'The id_token "target_link_uri" does not match the one recorded at login initiation.',
            );
        }

        if ($stateRecord['deployment_id'] !== null) {
            $deploymentId = $claims[ClaimUris::DEPLOYMENT_ID] ?? null;
            if ($deploymentId !== $stateRecord['deployment_id']) {
                throw new InvalidLaunchException(
                    InvalidLaunchReason::InvalidState,
                    'The id_token "deployment_id" does not match the one sent at login initiation.',
                );
            }
        }
    }
}
