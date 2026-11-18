# Pixelforge Studio

[Live application](https://pixelforge-studio-45724412085.europe-central2.run.app) · [CI](https://github.com/adamszrama/pixelforge-studio/actions/workflows/ci.yml)

A Laravel, Vue and Inertia image studio built around asynchronous generation and exact token accounting. Users can sign up, add demo credits, see a quote, generate an image and follow its progress. Operators can reconcile daily costs against Pixelforge's own usage endpoint.

## Stack

- PHP 8.5, Laravel 13, Inertia 3, Vue 3 and TypeScript
- PostgreSQL in production; SQLite for quick local development
- Database-backed queues, sessions, cache and distributed locks
- Cloud Run service + scheduled Cloud Run worker job + Cloud SQL
- PHPUnit, including real concurrent PostgreSQL settlement and reservation tests

## Run locally

Requirements: PHP 8.5 with mbstring, intl, PDO SQLite (or PostgreSQL), Composer, Node 24 and npm.

```sh
composer install
cp .env.example .env
php artisan key:generate
php -r "touch('database/database.sqlite');"
php artisan migrate
npm ci
npm run build
composer dev
```

Visit `http://localhost:8000`, register, and use **Buy credits** to add $10 of simulated credit. No card or real payment is involved. The dev command runs the web server, Vite, queue worker and scheduler together.

Create a **separate local API key** in the [Pixelforge dashboard](https://pixelforge.promptedit.dev/). Configure:

```dotenv
PIXELFORGE_API_KEY=your-local-key
PIXELFORGE_WEBHOOK_SECRET=your-local-signing-secret
```

Expose your app with a tunnel and set that key's webhook URL to `https://your-tunnel/webhooks/pixelforge`. Use a different key for production. Never commit `.env`, keys, signed image URLs or database dumps.

The public pricing endpoint works without a key, so the quote UI can be explored before setting up the integration. Generation stays disabled server-side until a key is configured.

## What is implemented

- Session authentication, CSRF protection, throttled registration/login and user-scoped history.
- Prompt, quality, size and up to four reference URLs, validated on the server.
- Debounced server-calculated quote, with midpoint estimate and maximum reservation at current rates.
- Stable client request UUID, also used as the provider's permanent idempotency key.
- Durable submission intent in the database, recovered by the scheduler if queue dispatch is lost.
- Bounded retries with exponential backoff, jitter and `Retry-After` support.
- Signed, deduplicated webhook processing with monotonic status transitions.
- Polling fallback for missing webhook deliveries; browser updates every five seconds.
- Exact settled costs, free failures, balance reservations and idempotent fake purchases.
- Operator report by UTC completion date and user, with explicit `/v1/usage` discrepancies.

## Money and concurrency

All persisted monetary amounts are **integer picodollars**: $1 = 1,000,000,000,000 units. Pixelforge publishes nanodollar costs; the finer unit preserves an exact 25% markup, including a quarter of a nanodollar. `brick/math` parses decimal rates without passing money through floating-point arithmetic.

For each successful generation:

```text
provider cost = Σ(tokens × that generation's rate / 1,000,000)
user charge   = provider cost × 1.25
profit        = user charge − provider cost
```

Quotes use `/v1/pricing`, cached for 60 seconds. Settlement uses the **generation's own rate snapshot**, never today's rates or the quote. All four billable token types are included. `total_tokens` is not billed again. Reports use the provider's `completed_at` in UTC, including completions crossing midnight.

Reservations cover the documented maximum token usage at quote-time rates. The quote is an estimate, not a guaranteed price cap: a provider rate change between quoting and acceptance can exceed it. The exact charge is still recorded; if that produces a negative available balance, further generation is blocked until credits are added. This is deliberate rather than silently under-reporting provider costs.

Wallet mutations lock the user row first, then the generation row. Settlement, ledger insertion, reservation release and balance debit commit together. A unique ledger constraint on `generation_id` supplies a second barrier against duplicate charging. Failed provider generations have a zero-valued settlement; rejected submissions have explicit zero costs without a debit.

See [the architecture notes](docs/architecture.md) for delivery and failure handling.

## Operator access

Register a normal account, then grant its role from a trusted terminal:

```sh
php artisan app:make-admin operator@example.com
```

The **Reports** navigation item appears only for admins, and the endpoint checks authorization independently. Reports show complete decimal values and reconcile the current environment's API key. A provider outage is shown as **unverified**, never as a zero-cost match.

## Verification

```sh
vendor/bin/phpunit
vendor/bin/pint --test
npm run typecheck
npm run build
npx prettier --check resources/js resources/css vite.config.js
```

The default test run uses in-memory SQLite; two concurrent locking tests require PostgreSQL and `pcntl`. CI runs the entire suite on both databases. To run the PostgreSQL version locally against a **dedicated disposable test database**:

```sh
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE=studio_test DB_USERNAME=studio DB_PASSWORD=testing-only \
vendor/bin/phpunit
```

Tests exercise duplicate deliveries, terminal events before submission responses, out-of-order processing events, unknown event recovery, signature tampering/age, transaction rollback, exact markup, rate changes, UTC reporting, timeout replay, bounded overload retries, quota failures, missing webhooks, lost dispatches, affordability and concurrent requests.

Use `[succeed] [duplicate]`, `[fail]`, `[slow]`, `[blocked]` and `[overloaded]` in real prompts to verify the deployed integration. The [acceptance checklist](docs/acceptance.md) describes expected outcomes. Test fixtures mirror the published contract; only a live-key test verifies provider interoperability.

## Deployment and operations

See [Cloud Run deployment](docs/deployment.md). The repository contains a multi-stage Dockerfile, Cloud Build config and GitHub Actions verification. Production secrets are read from Secret Manager, not built into the image.

Images are signed provider URLs and expire after 24 hours. The app deliberately does not download or retain them. Failed jobs and requests needing review should be monitored; the recovery command is documented below.

```sh
php artisan pixelforge:sync
php artisan pixelforge:resume GENERATION_UUID
```

`resume` keeps the original request and idempotency key. Use it after investigating an ambiguous submission and fixing connectivity. Never change the key to “retry” an accepted request: that would create another billable generation.

## Dependency note

At implementation time, `npm audit` reports a ReDoS advisory in the build-time `picomatch` dependency chain, including the newest published version. It is not shipped as a server runtime dependency; the matching inputs here are repository-controlled build globs. Do not downgrade the framework blindly to silence the advisory. Update the affected tooling when a compatible patched release is available. Composer's audit is clean.

API contract: [Pixelforge documentation](https://pixelforge.promptedit.dev/docs).
