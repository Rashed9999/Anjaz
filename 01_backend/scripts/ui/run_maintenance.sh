#!/usr/bin/env bash
set -uo pipefail
cd "$(dirname "$0")/../.."
export DB_DATABASE="${DB_DATABASE:-amial_conc}" SESSION_DRIVER=database CACHE_STORE=database QUEUE_CONNECTION=database
export AMIAL_DISABLE_ADMIN_CAPTCHA=true NODE_PATH=/opt/node22/lib/node_modules PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers
PORT=8199
php artisan config:clear >/dev/null 2>&1
php artisan migrate:fresh --force >/dev/null 2>&1
php artisan db:seed --class=DemoDataSeeder --force >/dev/null 2>&1
php artisan db:seed --class=FeatureFlagsSeeder --force >/dev/null 2>&1
php artisan passport:install --no-interaction >/dev/null 2>&1
php artisan serve --host=127.0.0.1 --port=$PORT >/tmp/maint_serve.log 2>&1 &
SPID=$!
for i in $(seq 1 15); do curl -s -o /dev/null "http://127.0.0.1:$PORT/admin/auth/login" && break; sleep 1; done
mkdir -p /tmp/maint_shots
BASE_URL="http://127.0.0.1:$PORT" SHOTS_DIR=/tmp/maint_shots node scripts/ui/maintenance_flow.mjs
RC=$?
kill $SPID 2>/dev/null
exit $RC
