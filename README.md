# php-lti / lti1p3

A PHP library for the tool side of LTI 1.3 Advantage. It handles the OIDC login and launch, Deep Linking 2.0, the Assignment and Grades Service (AGS) 2.0, and the Names and Role Provisioning Service (NRPS) 2.0. It works with any framework, or none.

It was built against D2L Brightspace, but it follows the 1EdTech specs, so it should work with any LMS that follows them too.

All of the features above are implemented and covered by an automated test suite. The tests use real cryptography and a real local HTTP server, with no mocks. The library has not been run against a live Brightspace tenant yet. [TESTPLAN.md](TESTPLAN.md) lists what has been verified and what is still waiting on a sandbox.

## Requirements

- PHP 8.1 or newer with the `openssl` and `json` extensions. Most PHP installs have both.
- An HTTP client and message factories that implement PSR-7, PSR-17, and PSR-18. Guzzle 7 covers all three, and it is what this project's tests and example use.

## Setup

This project uses [Composer](https://getcomposer.org/) to manage dependencies. Install Composer if you don't have it, then run:

```bash
composer install
```

These Composer scripts cover day-to-day development:

```bash
composer test       # run the test suite (PHPUnit)
composer stan       # static analysis (PHPStan, level 9)
composer cs         # coding standard check (PHP_CodeSniffer, PSR-12)
composer cs-fix     # fix coding standard violations automatically
composer check      # cs, then stan, then test
```

## What your application provides

The library has no hard dependency on an HTTP stack, a database, or a cache server. You hand it these pieces:

| You provide | The library uses it for |
|---|---|
| A PSR-18 HTTP client and PSR-17 factories | Calls to the platform, and building requests and responses |
| A class that implements `RegistrationRepositoryInterface` | Looking up platform registrations in your own database |
| A PSR-16 cache (optional, one is included) | Short-lived data such as login state and access tokens. See [Caching](#caching). |

JWTs are signed and verified with [`firebase/php-jwt`](https://github.com/firebase/php-jwt) ^7.0. Inbound tokens must use RS256. The JWKS fetcher drops any key that isn't RSA with RS256 before it can be used to check a signature.

## Usage

The steps below go in order. Each code sample reuses variables from the earlier ones.

### 1. Generate a signing key

```bash
php bin/generate-keypair.php --kid=2026-01-key-1
```

This writes a private and public key to `working/keys` and prints a JWK. Your tool publishes that JWK at its JWKS URL in step 4.

### 2. Create a cache

```php
use PhpLti\Lti1p3\Cache\FileCache;

$cache = new FileCache(__DIR__ . '/working/cache');
```

That is the whole setup. The directory is created if it doesn't exist. Pass this same `$cache` to every class below that asks for one.

If your application already has Redis, Memcached, or another PSR-16 cache, pass that instead. [Caching](#caching) explains when you should.

### 3. Implement `RegistrationRepositoryInterface`

```php
use PhpLti\Lti1p3\Registration\{Registration, RegistrationRepositoryInterface, ToolKeyPair};

final class DatabaseRegistrationRepository implements RegistrationRepositoryInterface
{
    public function findForLoginInitiation(string $issuer, ?string $clientId): ?Registration
    {
        // Look up your stored platform registration by issuer (and client_id if given).
        // Return null if nothing matches or the match is ambiguous. Never throw.
    }

    public function findForLaunch(string $issuer, string $clientId, string $deploymentId): ?Registration
    {
        // The same lookup, also scoped to one deployment_id.
    }
}
```

### 4. Serve your JWKS endpoint

```php
use PhpLti\Lti1p3\Security\Jwt\JwksBuilder;

$jwks = (new JwksBuilder())->build($registration); // ['keys' => [...]]
// Return this as the JSON body of your tool's JWKS URL.
```

### 5. Handle the OIDC login request

```php
use PhpLti\Lti1p3\OidcLogin\LoginInitiationHandler;

$handler = new LoginInitiationHandler($registrationRepository, $cache, $psr17ResponseFactory);
$response = $handler->handle($serverRequest, 'https://your-tool.example.com/lti/launch');
// $response is a PSR-7 302 redirect to the platform's auth endpoint. Return it unchanged.
```

### 6. Handle the launch

The platform POSTs back to your redirect URI. Validate that request:

```php
use PhpLti\Lti1p3\OidcLogin\LaunchValidator;
use PhpLti\Lti1p3\Security\Jwt\{JwksFetcher, JwtValidator};
use PhpLti\Lti1p3\Message\LtiResourceLinkRequest;
use PhpLti\Lti1p3\Message\DeepLinking\LtiDeepLinkingRequest;

$jwksFetcher = new JwksFetcher($httpClient, $requestFactory, $cache);
$jwtValidator = new JwtValidator($jwksFetcher, $cache);
$validator = new LaunchValidator($registrationRepository, $cache, $jwtValidator);

$message = $validator->validate($serverRequest); // throws InvalidLaunchException on any failure

if ($message instanceof LtiResourceLinkRequest) {
    // $message->subject, ->roles, ->resourceLink, ->context, ->custom, ...
} elseif ($message instanceof LtiDeepLinkingRequest) {
    // $message->deepLinkingSettings->deepLinkReturnUrl, ->acceptTypes, ...
}
```

### 7. Call the platform (AGS and NRPS)

```php
use PhpLti\Lti1p3\Services\AccessTokenService;
use PhpLti\Lti1p3\Services\Ags\{AssignmentsGradesService, Score, ActivityProgress, GradingProgress};
use PhpLti\Lti1p3\Services\Nrps\NamesRoleService;

$accessTokenService = new AccessTokenService($httpClient, $requestFactory, $streamFactory, $cache);
$ags = new AssignmentsGradesService($httpClient, $requestFactory, $streamFactory, $accessTokenService);
$nrps = new NamesRoleService($httpClient, $requestFactory, $accessTokenService);

// $endpoint->lineItemUrl is only set when exactly one line item belongs to
// this resource link. Otherwise create one first with
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
    $roster = $nrps->getMembers($registration, $endpoint); // list<Member>, follows pagination for you
}
```

If these calls come back `invalid_client` while launches work fine, read [Access-token audience](#access-token-audience).

### 8. Respond to a Deep Linking request

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
    ['JWT' => $response->toJwt()], // the spec names this field "JWT", not "id_token"
);
```

## Caching

The library caches four things. You don't have to manage any of them yourself.

| What | Kept for | Why |
|---|---|---|
| The platform's JWKS (public keys) | 1 hour | So a launch doesn't fetch the keys every time |
| Login state | 5 minutes, and deleted as soon as the launch uses it | Ties a launch to the login request that started it |
| Nonces | 1 hour | Rejects an id_token that is sent a second time |
| Service access tokens | The token's lifetime minus 60 seconds | So AGS and NRPS calls don't request a new token each time |

To change a duration, pass it to the constructor: `cacheTtlSeconds` on `JwksFetcher`, `stateTtlSeconds` on `LoginInitiationHandler`, or `nonceTtlSeconds` on `JwtValidator`. For example:

```php
$jwksFetcher = new JwksFetcher($httpClient, $requestFactory, $cache, cacheTtlSeconds: 600);
```

### Using the included `FileCache`

`FileCache` keeps one file per cached item in the directory you give it. It needs no server and no configuration, which makes it a good fit for a tool that runs on a single web server.

Three things to know:

1. Keep the directory private. Put it outside your web root and make sure only your application can write to it. `FileCache` creates it with mode `0700`.
2. Clean it up on a schedule. Each launch leaves one small nonce file behind, and nothing reads it again unless someone replays the launch. Run this once a day from cron or your scheduler:

   ```php
   use PhpLti\Lti1p3\Cache\FileCache;

   $removed = (new FileCache(__DIR__ . '/working/cache'))->removeExpired();
   ```

3. Don't use it across several web servers unless they share the directory. The login request and the launch are two separate HTTP requests. If they land on different servers with different disks, the launch won't find its login state and will be rejected.

### Using your own cache

Any PSR-16 `Psr\SimpleCache\CacheInterface` works. Pass it wherever the steps above pass `$cache`:

```php
$cache = $yourRedisBackedPsr16Cache;
```

Choose this when you run more than one web server, or when your application already has a cache you'd like to reuse.

The cache has to keep data between requests. An in-memory array cache will not work, because the launch arrives in a different request than the login that stored its state.

Every key the library writes starts with `lti1p3_` and contains only letters, digits, and underscores, hyphens, or dots, so the keys are valid in any PSR-16 backend and are easy to tell apart from your application's own.

## Access-token audience

When the library requests a service access token, it signs a client assertion. The `aud` claim of that assertion defaults to the platform's token endpoint, which is what the 1EdTech Security Framework specifies.

Some platforms want a different value. Brightspace is one: its registration page publishes a "Brightspace OAuth2 Audience" (`https://api.brightspace.com/auth/token`) that is not the token URL you POST to. Canvas does the same and wants `https://canvas.instructure.com/login/oauth2/token` whatever the region-specific token URL is.

This is an easy mistake to miss. Launches never touch the token endpoint, so they keep working while every AGS and NRPS call fails with `invalid_client`. To set the audience, pass the optional last argument to `Registration`:

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

Only the `aud` claim changes. The token request still goes to `platformAuthenticationTokenUrl`. `$registration->accessTokenAudience()` returns the value in effect, and `$registration->platformAudience` is `null` when you haven't set one.

The audience doesn't have to be a URL, because some platforms use an opaque identifier, so it skips the HTTPS check that the endpoint URLs get. It can't be an empty string.

## CLI helpers (`bin/`)

### `generate-keypair.php`

Generates a new RSA signing key pair for your tool and prints the matching JWK. Run it once when you set up the tool, and again whenever you rotate keys. The library never calls it at runtime.

```bash
php bin/generate-keypair.php --kid=<kid> [--bits=2048] [--out-dir=working/keys]
php bin/generate-keypair.php --help
```

For example:

```bash
php bin/generate-keypair.php --kid=2026-01-key-1
php bin/generate-keypair.php --kid=2026-07-key-2 --bits=4096 --out-dir=/etc/my-tool/keys
```

It writes `<kid>.private.pem` (mode `0600`) and `<kid>.public.pem` into `--out-dir`, which defaults to `working/keys` under the current directory, and prints the key's JWK as JSON on stdout. Keep the private key out of version control. Give both PEM files to a `PhpLti\Lti1p3\Registration\ToolKeyPair` when you build your `Registration`.

When this library is installed as a dependency of your application, Composer makes the same script available at `vendor/bin/generate-keypair.php`.

## Registering this tool with D2L Brightspace

A Brightspace admin or developer registers the tool once per environment, in two steps, through the LE API (`/d2l/api/le/(version)/ltiadvantage/...`).

Step 1 is the tool registration. You provide:

| Field | Value |
|---|---|
| `OpenIDConnectLoginUrl` | Your tool's login endpoint (step 5 above) |
| `KeysetUrl` | Your tool's JWKS URL (step 4 above) |
| `RedirectUrls` | Array of authorized launch and redirect URIs (step 6 above) |

Step 2 is the deployment. It is linked to the registration by `ClientId`, and it sets which user fields Brightspace sends (`SendUserFirstName`, `SendUserEmail`, `SendD2LUserId`, and so on).

Brightspace then returns the values you need to build your `Registration` object:

| Brightspace field | `Registration` constructor argument |
|---|---|
| `BrightspaceIssuer` | `issuer` |
| The `ClientId` from your deployment | `clientId` |
| `BrightspaceOIDCAuthenticationEndpoint` | `platformAuthenticationLoginUrl` |
| `BrightspaceOAuth2AccessTokenUrl` | `platformAuthenticationTokenUrl` |
| `BrightspaceKeysetUrl` | `platformJwksUrl` |
| `BrightspaceOAuth2Audience` | `platformAudience` (required for Brightspace, because it is not the token URL) |

Brightspace's JWKS endpoint usually looks like `https://<your-subdomain>.brightspace.com/d2l/.well-known/jwks`.

A deployment's `EnabledExtensions` array says which Advantage services are active for it. This library supports AGS, Deep Linking, and NRPS. Recent Brightspace versions always enable Deep Linking, whatever the request asks for.

The OIDC login URL and the redirect URIs must use HTTPS. Brightspace rejects `http://` at registration time, and this library applies the same rule when you construct a `Registration`. The one exception is loopback addresses, which the library's own local test fixtures use.

## Known limitations

- No end-to-end testing against a live Brightspace tenant yet. That needs a sandbox; see [TESTPLAN.md](TESTPLAN.md).
- `AssignmentsGradesService::listLineItems()` fetches a single page and does not follow `Link` header pagination. NRPS roster fetches do paginate.
- Submission Review and the Platform Notification Service are not implemented in this version.

## License

MIT. See [LICENSE](LICENSE).
