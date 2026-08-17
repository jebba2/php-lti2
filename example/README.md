# php-lti example tool

A minimal LTI 1.3 tool built on [`phplti/lti1p3`](..), plus a bundled **platform simulator**
so the whole login → launch → AGS/NRPS/Deep-Linking flow can be exercised locally without a
real Brightspace tenant. The simulator is demo-only code standing in for the platform side of
the protocol — it isn't part of the library, which only implements the tool side.

## Setup

```bash
composer install
php bin/setup.php
```

`bin/setup.php` generates two RSA key pairs (one for this tool, one for the simulator "platform")
and writes `config/config.php` wiring them together. It's safe to re-run at any time.

## Running it

In two separate terminals, from this `example/` directory:

```bash
PHP_CLI_SERVER_WORKERS=4 php -S localhost:8000 -t public              # the tool
PHP_CLI_SERVER_WORKERS=4 php -S localhost:8001 simulator/router.php   # the platform simulator
```

`PHP_CLI_SERVER_WORKERS` isn't optional here: the AGS demo has the tool call the simulator's
`/token` endpoint, which calls *back* into the tool's `/jwks.php` to verify the client
assertion. `php -S` defaults to a single worker per process, so that second call has nowhere
to be served — the one worker is still busy with the first request — and the whole thing hangs
until PHP's execution time limit kills it. Both directions need more than one worker.

Then open **http://localhost:8001/** and click "Launch as a resource link" or "Launch as a
Deep Linking request".

## What you'll see

- **Resource link launch** (`public/launch.php`): the platform's signed id_token is validated
  (real RSA signature check, replay/state/nonce protection, claim binding — all via
  `LaunchValidator`), then the page shows the launch's subject, roles, resource link, and
  custom parameters, with buttons to:
  - **Publish a demo score (AGS)** — calls back into the simulator's Assignment and Grades
    Service: requests an access token via a real signed client-assertion JWT, publishes a
    score, then reads the result back.
  - **Fetch course roster (NRPS)** — calls the simulator's Names and Role Provisioning Service
    and lists the (fake) course roster.
- **Deep Linking launch**: shows the platform's `deep_linking_settings`, with a form to submit
  a selected content item. Submitting builds and signs an `LtiDeepLinkingResponse`, auto-posts
  it back to the simulator (field name `JWT`, not `id_token` — see the main README), and the
  simulator verifies the signature against the tool's own JWKS before showing what it received.

## Files

```
bin/setup.php              generates keys + config/config.php
config/config.php           generated — issuer/client_id/deployment_id/kids (not committed)
public/                      the tool: login.php, launch.php, jwks.php, AGS/NRPS/Deep-Linking actions
simulator/router.php         the platform simulator (single php -S router script)
simulator/src/Database.php   tiny JSON-file "gradebook" backing the simulator's AGS endpoints
src/Bootstrap.php            wires config + keys into a Registration
src/ConfigRegistrationRepository.php  RegistrationRepositoryInterface for this single-tenant demo
src/FileCache.php             PSR-16 cache backed by files (needed since php -S is one process per request)
working/                      generated keys, cache files, simulator's JSON "database" — not committed
```

## Using this against a real Brightspace instance instead

Everything under `public/` is real, framework-agnostic tool code — nothing about it depends on
the simulator. To point it at a real Brightspace tenant instead:

1. Run `php bin/setup.php` for a real tool key pair (or just reuse `working/keys/tool-key-1.*`).
2. Register this tool with Brightspace using this tool's `login.php` and `jwks.php` URLs — see
   the main library README's ["Registering this tool with D2L Brightspace"](../README.md#registering-this-tool-with-d2l-brightspace)
   section for the exact fields.
3. Edit `config/config.php`: replace `simulator_base_url`/`client_id`/`deployment_id` with the
   values Brightspace gives you back, and change `tool_base_url` to wherever this app is
   actually reachable from Brightspace (must be HTTPS — `UrlSecurity` rejects plain http to a
   non-loopback host, which is exactly why this only works with `localhost` unmodified).
4. Deploy `public/` somewhere Brightspace can reach it and try a real launch.
