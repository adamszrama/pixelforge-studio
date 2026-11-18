# Cloud Run deployment

## Runtime layout

- A public Cloud Run **service** serves Laravel through Apache/PHP on port 8080.
- Cloud SQL PostgreSQL stores users, generations, ledger, webhook inbox, queue, cache and sessions.
- A bounded Cloud Run **job** runs `deploy/worker.sh` every minute through Cloud Scheduler. It invokes Laravel's scheduler and drains the database queue for up to 50 seconds.
- A separate one-off job runs migrations. Deployments never run migrations on every web request or container startup.
- Secret Manager supplies `APP_KEY`, `DB_PASSWORD`, `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET`.

No background worker depends on idle Cloud Run service CPU. Both the service and job use the same image and database. Database queue `retry_after` is 90 seconds, greater than the 30-second job timeout; overlapping work is additionally guarded by cache locks and transactional settlement.

The application role needs Cloud SQL Client and access only to its own secrets. Cloud Scheduler uses a separate identity with permission to invoke only the worker job. Public access applies to the web service, not its database or worker job.

## Build

```sh
gcloud builds submit --project=YOUR_PROJECT --region=YOUR_REGION \
  --config=cloudbuild.yaml \
  --substitutions=_IMAGE=YOUR_REGION-docker.pkg.dev/YOUR_PROJECT/pixelforge/app:REVISION
```

Create the repository, database, dedicated database user, runtime service account and secrets once. Supply secret values through standard input or a secret-manager UI; avoid putting credentials in shell history or committed files.

## Environment

```dotenv
APP_NAME="Pixelforge Studio"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://YOUR_SERVICE_URL
LOG_CHANNEL=stderr
LOG_LEVEL=warning
DB_CONNECTION=pgsql
DB_HOST=/cloudsql/YOUR_PROJECT:YOUR_REGION:YOUR_INSTANCE
DB_PORT=5432
DB_DATABASE=pixelforge
DB_USERNAME=pixelforge
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database
```

Attach the Cloud SQL instance to each service/job. Bind the four secrets to their corresponding environment variable names. Use the same `APP_KEY` across all revisions; changing it invalidates existing sessions.

Run migrations with the same image/environment:

```sh
php artisan migrate --force
```

Then deploy the service with a modest maximum instance count and the worker job with task count 1, parallelism 1, timeout 90 seconds and no platform retries. The application owns retry timing; the scheduler recovers unfinished work.

Cloud Scheduler's HTTP target is:

```text
https://run.googleapis.com/v2/projects/YOUR_PROJECT/locations/YOUR_REGION/jobs/pixelforge-worker:run
```

Use POST, an empty JSON body, and an **OAuth** service-account token for the Google API target. Schedule `* * * * *` in UTC. Grant that identity `roles/run.invoker` on the worker job, not project-wide administrator permissions.

## Provider setup

Create a key dedicated to production and set its webhook URL:

```text
https://YOUR_SERVICE_URL/webhooks/pixelforge
```

Store its signing secret and API key in Secret Manager. A dashboard ping should return 204. Run the [live acceptance checklist](acceptance.md), then grant the operator account admin access through a one-off trusted command.

## Recovering an uncertain request

Inspect its provider ID, original UUID, submission attempt count and message. Inspect provider webhook delivery logs and the relevant usage day. Restore connectivity/credentials for the same environment key, then:

```sh
php artisan pixelforge:resume GENERATION_UUID
```

This resets the automatic retry budget without changing the request body or idempotency key. A replay retrieves the original accepted generation instead of creating a second charge. Do not manually release its reserved balance until provider acceptance is conclusively resolved.

## Monitoring and costs

Monitor `/up`, failed queue jobs, nonzero report differences, unprocessed webhook events and `needs_review` generations. Keep Cloud SQL backups enabled. Limit Cloud Run instances and use a bounded worker job rather than a permanently allocated worker for this demo.

Cloud Run can scale to zero, but Cloud SQL has ongoing cost. This deployment uses a separate database/user on the user's existing Cloud SQL instance to avoid another always-on database instance. The provider is simulated; GCP infrastructure is not free by definition.
