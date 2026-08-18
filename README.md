# php-lti / lti1p3

Framework-agnostic PHP library implementing the **tool side** of LTI 1.3 Advantage: OIDC login/launch, Deep Linking 2.0, Assignment and Grades Service (AGS) 2.0, and Names and Role Provisioning Service (NRPS) 2.0. Built primarily against D2L Brightspace as the Platform, but implemented to the 1EdTech specs so it should work with any spec-compliant LMS.

Status: core feature set (login/launch, AGS, NRPS, Deep Linking) is implemented and covered by an automated test suite using real cryptography and a real local HTTP fixture server (no mocks). It has **not yet been exercised against a live Brightspace tenant** — see [TESTPLAN.md](TESTPLAN.md) for exactly what's verified and what's still pending a real sandbox.

## Requirements

- PHP 8.1+, with the `openssl` and `json` extensions (standard in most PHP installs)
- Your application supplies its own PSR-7/PSR-17/PSR-18 HTTP implementation and PSR-16 cache backend (see [Design](#design))

## Setup

This project uses [Composer](https://getcomposer.org/) for dependency management. Install Composer first if you don't have it, then:

```bash
composer install
```

Useful composer scripts (see `composer.json` for the full list):

```bash
composer test       # run the test suite (PHPUnit)
composer stan        # static analysis (PHPStan, level 9)
composer cs          # coding standard check (PHP_CodeSniffer, PSR-12)
composer cs-fix      # auto-fix coding standard violations
composer check       # cs + stan + test, in that order
```

## Design

- **HTTP**: framework-agnostic via PSR-7 (`psr/http-message`), PSR-17 (`psr/http-factory`) for building requests/responses, and PSR-18 (`psr/http-client`) for outbound calls to the Platform. Your application supplies its own implementation of each (Guzzle, Symfony, Laminas, etc.) — this library has no hard dependency on any concrete HTTP stack.
- **Caching**: ephemeral state (OIDC login state/nonce, replay protection, cached service access tokens) goes through an injected `Psr\SimpleCache\CacheInterface` (PSR-16). Bring whatever cache backend your app already uses.
- **Persistence**: durable business data (platform/tool registrations) is defined as a repository interface (`PhpLti\Lti1p3\Registration\RegistrationRepositoryInterface`) that your application implements against its own database.
- **JWT**: signing and verification via [`firebase/php-jwt`](https://github.com/firebase/php-jwt) ^7.0. Only RS256 is accepted for inbound tokens — the JWKS fetcher filters out any non-RSA/non-RS256 key entries before they can ever be used to verify a signature.

## Usage

### 1. Generate a signing key and register with the platform

```bash
php bin/generate-keypair.php --kid=2026-01-key-1
```

This prints a JWK — add it to whatever your app publishes at its own JWKS endpoint (built via `JwksBuilder`, see below).

### 2. Implement `RegistrationRepositoryInterface`

```php
use PhpLti\Lti1p3\Registration\{Registration, RegistrationRepositoryInterface, ToolKeyPair};

final class DatabaseRegistrationRepository implements RegistrationRepositoryInterface
{
    public function findForLoginInitiation(string $issuer, ?string $clientId): ?Registration
    {
        // Look up your stored platform registration by issuer (+ client_id if given).
        // Return null if none found or the lookup is ambiguous — never throw.
    }

    public function findForLaunch(string $issuer, string $clientId, string $deploymentId): ?Registration
    {
        // Same lookup, additionally scoped to a specific deployment_id.
    }
}
```

### 3. Serve your JWKS endpoint

```php
use PhpLti\Lti1p3\Security\Jwt\JwksBuilder;

$jwks = (new JwksBuilder())->build($registration); // -> ['keys' => [...]]
// Return this as the JSON body of your tool's published JWKS URL.
```

### 4. Handle the OIDC login-initiation request

```php
use PhpLti\Lti1p3\OidcLogin\LoginInitiationHandler;

$handler = new LoginInitiationHandler($registrationRepository, $cache, $psr17ResponseFactory);
$response = $handler->handle($serverRequest, 'https://your-tool.example.com/lti/launch');
// $response is a PSR-7 302 redirect back to the platform's auth endpoint — return it as-is.
```

### 5. Handle the launch (platform's POST back to your redirect_uri)

```php
use PhpLti\Lti1p3\OidcLogin\LaunchValidator;
use PhpLti\Lti1p3\Message\LtiResourceLinkRequest;
use PhpLti\Lti1p3\Message\DeepLinking\LtiDeepLinkingRequest;

$validator = new LaunchValidator($registrationRepository, $cache, $jwtValidator);
$message = $validator->validate($serverRequest); // throws InvalidLaunchException on any failure

if ($message instanceof LtiResourceLinkRequest) {
    // $message->subject, ->roles, ->resourceLink, ->context, ->custom, ...
} elseif ($message instanceof LtiDeepLinkingRequest) {
    // $message->deepLinkingSettings->deepLinkReturnUrl, ->acceptTypes, ...
}
```

`$jwtValidator` is a `PhpLti\Lti1p3\Security\Jwt\JwtValidator`, constructed from a `JwksFetcher` (which needs your PSR-18 client + PSR-17 request factory + PSR-16 cache) and your nonce-tracking cache.

### Configurable access-token audience

When the library requests a service access token, it signs a client assertion whose `aud` claim is, per the 1EdTech Security Framework, the platform's token endpoint. That is the default, and it is right for any platform that does not say otherwise.

Plenty of platforms do say otherwise, and Brightspace is one of them — its registration page publishes a "Brightspace OAuth2 Audience" (`https://api.brightspace.com/auth/token`) that is deliberately not the token url you POST to. Canvas behaves the same way, wanting `https://canvas.instructure.com/login/oauth2/token` regardless of the region-specific token url. Get this wrong and launches keep working perfectly while every AGS and NRPS call comes back `invalid_client`, because launches never touch the token endpoint. Pass the optional final `Registration` argument to override it:

```php
$registration = new Registration(
    $issuer,
    $clientId,
    $deploymentIds,
    $platformAuthenticationLoginUrl,
    $platformAuthenticationTokenUrl,
    $platformJwksUrl,
    $toolKeyPairs,
    platformAudience: 'https://canvas.instructure.com/login/oauth2/token', // optional
);
```

Only the `aud` claim changes — the token request still goes to `platformAuthenticationTokenUrl`. `$registration->accessTokenAudience()` returns whichever value is in effect, and `$registration->platformAudience` is `null` when no override is configured. The audience is not required to be a URL and is not subject to the HTTPS check the endpoint urls get, since some platforms use an opaque identifier; it just can't be an empty string.

### 6. Call back into the platform (AGS / NRPS)

```php
use PhpLti\Lti1p3\Services\AccessTokenService;
use PhpLti\Lti1p3\Services\Ags\{AssignmentsGradesService, Score, ActivityProgress, GradingProgress};
use PhpLti\Lti1p3\Services\Nrps\NamesRoleService;

$accessTokenService = new AccessTokenService($httpClient, $requestFactory, $streamFactory, $cache);
$ags = new AssignmentsGradesService($httpClient, $requestFactory, $streamFactory, $accessTokenService);
$nrps = new NamesRoleService($httpClient, $requestFactory, $accessTokenService);

// $endpoint->lineItemUrl is only set when exactly one line item is
// associated with this resource link; otherwise create one first via
// $ags->createLineItem($registration, $endpoint, new LineItem(...)).
if (($endpoint = $message->agsEndpoint()) && $endpoint->lineItemUrl !== null && $message->subject !== null) {
    $ags->publishScore($registration, $endpoint->lineItemUrl, new Score(
        userId: $message->subject,
        activityProgress: ActivityProgress::Completed,
        gradingProgress: GradingProgress::FullyGraded,
        scoreGiven: 8.5,
        scoreMaximum: 10.0,
    ));
}

if ($endpoint = $message->nrpsEndpoint()) {
    $roster = $nrps->getMembers($registration, $endpoint); // list<Member>, pagination followed automatically
}
```

### 7. Respond to a Deep Linking request

```php
use PhpLti\Lti1p3\Message\DeepLinking\LtiDeepLinkingResponse;
use PhpLti\Lti1p3\Message\DeepLinking\ContentItem\LtiResourceLinkContentItem;
use PhpLti\Lti1p3\Http\FormPostRenderer;

$response = new LtiDeepLinkingResponse(
    $registration,
    $message->deploymentId,
    $message->rawClaims['iss'], // the platform's issuer, from the original request
    [new LtiResourceLinkContentItem(url: 'https://your-tool.example.com/launch/42', title: 'Selected item')],
    data: $message->deepLinkingSettings->data,
);

return (new FormPostRenderer($psr17ResponseFactory, $psr17StreamFactory))->render(
    $message->deepLinkingSettings->deepLinkReturnUrl,
    ['JWT' => $response->toJwt()], // field name is literally "JWT", not "id_token" — per spec
);
```

## CLI helpers (`bin/`)

### `generate-keypair.php`

Generates a new RSA signing key pair for your tool and prints the corresponding JWK to add to your published JWKS document. This is a one-time (or per key-rotation) setup step, not something the library does at runtime.

```bash
php bin/generate-keypair.php --kid=<kid> [--bits=2048] [--out-dir=working/keys]
php bin/generate-keypair.php --help
```

If this library is installed as a dependency of your application, the same script is available at `vendor/bin/generate-keypair.php` (via Composer's `bin` mechanism).

Writes `<kid>.private.pem` (mode `0600`) and `<kid>.public.pem` into `--out-dir` (default: `working/keys` under the current directory), and prints the key's JWK as JSON on stdout. Keep the private key out of version control; feed both PEMs into a `PhpLti\Lti1p3\Registration\ToolKeyPair` when building your `Registration`.

## Registering this tool with D2L Brightspace

Brightspace's registration is a two-step process (via its LE API, `/d2l/api/le/(version)/ltiadvantage/...`), done once per environment by a Brightspace admin/developer:

**Step 1 — Tool registration.** You provide:

| Field | Value |
|---|---|
| `OpenIDConnectLoginUrl` | Your tool's login-initiation endpoint (step 4 above) |
| `KeysetUrl` | Your tool's JWKS URL (step 3 above) |
| `RedirectUrls` | Array of authorized launch/redirect URIs (step 5 above) |

**Step 2 — Deployment**, linked to the registration by `ClientId`, plus which user-data fields to send (`SendUserFirstName`, `SendUserEmail`, `SendD2LUserId`, etc.).

Brightspace returns the values you need to build your `Registration` object:

| Brightspace field | Maps to `Registration` constructor arg |
|---|---|
| `BrightspaceIssuer` | `issuer` |
| (the `ClientId` from your deployment) | `clientId` |
| `BrightspaceOIDCAuthenticationEndpoint` | `platformAuthenticationLoginUrl` |
| `BrightspaceOAuth2AccessTokenUrl` | `platformAuthenticationTokenUrl` |
| `BrightspaceKeysetUrl` | `platformJwksUrl` |
| `BrightspaceOAuth2Audience` | `platformAudience` (required for Brightspace — it is not the token url) |

Brightspace's JWKS endpoint conventionally looks like `https://<your-subdomain>.brightspace.com/d2l/.well-known/jwks`. A `Deployment`'s `EnabledExtensions` array declares which Advantage services are active for it (AGS, Deep Linking, NRPS are the ones this library supports; Deep Linking is always enabled as of recent Brightspace versions regardless of what's requested).

Both the OIDC login URL and redirect URIs **must use HTTPS** — Brightspace rejects `http://` at registration time, and this library enforces the same rule when constructing a `Registration` (with a loopback exception used only by this library's own local test fixtures).

## Known limitations

- No live-Brightspace end-to-end testing yet (needs a sandbox tenant — see [TESTPLAN.md](TESTPLAN.md))
- `AssignmentsGradesService::listLineItems()` fetches a single page (no `Link` header pagination) — NRPS roster fetches do paginate
- Submission Review and Platform Notification Service are not implemented (out of scope for this version)

## License

MIT — see [LICENSE](LICENSE).
