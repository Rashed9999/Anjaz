#!/usr/bin/env bash
# AMIAL-UI-AUDIT-001 — يُشغّل التطبيق ثمّ يدقّق واجهته عبر متصفّح Chromium حقيقي.
set -uo pipefail
cd "$(dirname "$0")/../.."

export DB_DATABASE="${DB_DATABASE:-amial_conc}"
export SESSION_DRIVER=database CACHE_STORE=database CACHE_DRIVER=database QUEUE_CONNECTION=database
export NODE_PATH=/opt/node22/lib/node_modules
export PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers
PORT=8199

echo "═══ تهيئة + تشغيل التطبيق ═══"
php artisan config:clear >/dev/null 2>&1
php artisan migrate:fresh --force >/dev/null 2>&1
php artisan db:seed --class=DemoDataSeeder --force >/dev/null 2>&1
php artisan passport:install --no-interaction >/dev/null 2>&1

php artisan serve --host=127.0.0.1 --port=$PORT >/tmp/amial_serve.log 2>&1 &
SERVE_PID=$!
# انتظر جاهزية الخادم
for i in $(seq 1 15); do
  curl -s -o /dev/null "http://127.0.0.1:$PORT/api/v1/config" && break
  sleep 1
done

BASE_URL="http://127.0.0.1:$PORT" node scripts/ui/ui_audit.mjs
RC=$?

kill $SERVE_PID 2>/dev/null
exit $RC
