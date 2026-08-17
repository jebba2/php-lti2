<?php

declare(strict_types=1);

namespace PhpLti\Lti1p3\Message\DeepLinking;

use Firebase\JWT\JWT;
use PhpLti\Lti1p3\Message\ClaimUris;
use PhpLti\Lti1p3\Message\DeepLinking\ContentItem\ContentItem;
use PhpLti\Lti1p3\Registration\Registration;

/**
 * Builds the tool's signed response to a Deep Linking request: a JWT
 * naming the selected content items, signed with the tool's own key so the
 * platform can verify it against the tool's published JWKS.
 *
 * Per the LTI Deep Linking 2.0 spec, `iss`/`aud` are reversed relative to
 * the inbound launch: `iss` is the tool's own client_id, and `aud` is the
 * platform's issuer (the `iss` value from the original request) — the
 * platform is the verifier here, not the signer.
 */
final class LtiDeepLinkingResponse
{
    private const MESSAGE_TYPE = 'LtiDeepLinkingResponse';
    private const TOKEN_TTL_SECONDS = 300;

    /**
     * @param list<ContentItem> $contentItems
     */
    public function __construct(
        private readonly Registration $registration,
        private readonly string $deploymentId,
        private readonly string $platformIssuer,
        private readonly array $contentItems,
        private readonly ?string $data = null,
    ) {
    }

    public function toJwt(): string
    {
        $now = time();
        $signingKey = $this->registration->activeSigningKey();

        $claims = [
            'iss' => $this->registration->clientId,
            'aud' => $this->platformIssuer,
            'exp' => $now + self::TOKEN_TTL_SECONDS,
            'iat' => $now,
            ClaimUris::MESSAGE_TYPE => self::MESSAGE_TYPE,
            ClaimUris::VERSION => '1.3.0',
            ClaimUris::DEPLOYMENT_ID => $this->deploymentId,
            ClaimUris::CONTENT_ITEMS => array_map(
                static fn (ContentItem $item): array => $item->toArray(),
                $this->contentItems,
            ),
        ];

        if ($this->data !== null) {
            $claims[ClaimUris::DATA] = $this->data;
        }

        return JWT::encode($claims, $signingKey->privateKey, 'RS256', $signingKey->kid);
    }
}
