#!/bin/sh
set -eu
# Cloud Scheduler invokes this bounded Cloud Run Job every minute.
# The database lock also prevents concurrent scheduler deliveries from adding duplicate work.
php artisan schedule:run
php artisan queue:work --stop-when-empty --max-time=50 --timeout=30 --tries=1 --sleep=1
