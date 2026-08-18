# Test Plan

Manual verification checklist for this library, kept current alongside the automated test suite (`composer test`). Check items off as they're verified against real crypto / a real local fixture Platform server, per the no-mocks testing policy. Live-Brightspace end-to-end items are separate and blocked on having a Brightspace sandbox tenant.

## Automated suite

- [x] `composer test` passes with pristine output (no warnings/notices/deprecations)
- [x] `composer stan` (PHPStan level 9) passes clean
- [x] `composer cs` (PHPCS, PSR-12) passes clean

## Key management

- [x] `bin/generate-keypair.php` produces a valid RSA keypair (2048-bit min) and a matching JWKS entry
- [x] `JwksBuilder` produces a valid JWKS document from a `Registration`'s key set, including after key rotation (multiple `ToolKeyPair`s)

## JWKS fetching

- [x] `JwksFetcher` fetches and parses a real JWKS document from the local fixture Platform server
- [x] Result is cached via PSR-16 and not re-fetched within TTL
- [x] Unknown `kid` is rejected (firebase/php-jwt's own kid lookup throws, remapped to InvalidLaunchException by JwtValidator)
- [x] Non-allow-listed `alg`/`kty` entries are filtered out of the JWKS before a Key is ever built from them

## JWT / launch validation

- [x] Valid, freshly-signed id_token from the fixture Platform passes validation end-to-end
- [x] Expired id_token is rejected
- [x] Wrong-signature id_token is rejected
- [x] `iss` mismatch (including trailing-slash variants) is rejected
- [x] `aud` as a plain string is validated correctly
- [x] `aud` as an array requires and validates `azp`
- [x] Replayed `nonce` is rejected
- [x] `alg:none` / unsupported algorithm is rejected before verification is attempted
- [x] `JWT::$leeway` (global mutable state in firebase/php-jwt) is restored after both success and failure
- [x] Tampered/mismatched/unknown/replayed `state` is rejected
- [x] `target_link_uri` / `deployment_id` mismatch between login-initiation and launch is rejected
- [x] Anonymous launch (missing `sub`) is accepted and modeled as nullable, not an error
- [x] Tampered payload under an otherwise-valid original signature is rejected
- [x] Vendor `Firebase\JWT\*` exception types never escape the public API (always remapped to `InvalidLaunchException`, with the vendor exception preserved as `$previous`)
- [x] Clock-skew leeway boundary: comfortably within the window is accepted, comfortably beyond it is rejected (exact-instant boundary testing avoided — inherently racy against wall-clock time)
- [x] Plain-http platform URLs (login/token/JWKS) and `target_link_uri`/`redirect_uri` to a remote host are rejected; loopback (127.0.0.1/localhost) is allowed for local dev/testing

## OIDC login + core resource link launch

- [x] `LoginInitiationHandler` builds a correct redirect to the Platform's auth endpoint (params, state/nonce, cached login-state record)
- [x] Full login-initiation -> auth redirect -> launch roundtrip against the fixture server produces a correctly typed `LtiResourceLinkRequest`
- [x] Manual smoke check via a standalone script against the fixture server (real subprocess, real HTTP, real crypto, outside PHPUnit)
- [x] Unexpected `message_type` / unsupported LTI `version` is rejected
- [x] Missing `resource_link` claim is rejected
- [x] `custom`, `context`, and `tool_platform` claims are captured correctly

## Assignment and Grades Service (AGS)

- [x] Access token is requested via client_credentials JWT-bearer grant against the fixture token endpoint and cached (client assertion is a real signed JWT, verified against the tool's own JWKS)
- [x] Client assertion `aud` defaults to the platform token url, and uses the configured `platformAudience` override when the registration sets one
- [x] Line item create/read/update/delete against the fixture AGS endpoint
- [x] Score publish against the fixture AGS endpoint (correct `activityProgress`/`gradingProgress` values, Bearer token sent)
- [x] Result read against the fixture AGS endpoint

## Names and Role Provisioning Service (NRPS)

- [x] Roster fetch against the fixture NRPS endpoint
- [x] Pagination via `Link: rel="next"` header is followed correctly (verified across a real 2-page sequence)

## Deep Linking

- [x] `LtiDeepLinkingRequest` parses the `deep_linking_settings` claim correctly
- [x] `LtiDeepLinkingResponse` signs a JWT and `FormPostRenderer` posts a form field literally named `JWT` (not `id_token`) to `deep_link_return_url`
- [x] Full deep linking roundtrip against the fixture server (login -> launch dispatch -> response JWT -> form post -> platform-side verification)
- [x] `LaunchValidator` dispatches to `LtiResourceLinkRequest` or `LtiDeepLinkingRequest` based on `message_type`
- [x] Response JWT's `iss`/`aud` are correctly reversed relative to the launch (tool signs, aud = platform issuer)
- [x] `data` claim is echoed back when present in the request, omitted when absent

## Live Brightspace (blocked on sandbox tenant)

- [ ] Register this tool in a real Brightspace sandbox
- [ ] Real login -> launch roundtrip from a Brightspace course
- [ ] Real AGS score passback visible in the Brightspace gradebook
- [ ] Real NRPS roster fetch from a Brightspace course
- [ ] Real Deep Linking content selection from a Brightspace course
