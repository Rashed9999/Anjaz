#!/usr/bin/env bash
# AMIAL-OPS-CONSOLE-001 — يشغّل التطبيق ويختبر منصة العمليات عبر Chromium حقيقي.
set -uo pipefail
cd "$(dirname "$0")/../.."

export DB_DATABASE="${DB_DATABASE:-amial_conc}"
export SESSION_DRIVER=database CACHE_STORE=database CACHE_DRIVER=database QUEUE_CONNECTION=database
export AMIAL_DISABLE_ADMIN_CAPTCHA=true
export NODE_PATH=/opt/node22/lib/node_modules
export PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers
PORT=8199

echo "═══ تهيئة + تشغيل ═══"
php artisan config:clear >/dev/null 2>&1
php artisan migrate:fresh --force >/dev/null 2>&1
php artisan db:seed --class=DemoDataSeeder --force >/dev/null 2>&1
php artisan passport:install --no-interaction >/dev/null 2>&1

# عميل تجريبي للبحث في المنصة
CUSTOMER_PHONE=$(php artisan tinker --execute='echo \App\Models\User::where("type",2)->value("phone");' 2>/dev/null | tail -1)
echo "عميل تجريبي: $CUSTOMER_PHONE"

php artisan serve --host=127.0.0.1 --port=$PORT >/tmp/amial_console_serve.log 2>&1 &
SERVE_PID=$!
for i in $(seq 1 15); do curl -s -o /dev/null "http://127.0.0.1:$PORT/admin/auth/login" && break; sleep 1; done

mkdir -p /tmp/console_shots
BASE_URL="http://127.0.0.1:$PORT" CUSTOMER_PHONE="$CUSTOMER_PHONE" SHOTS_DIR=/tmp/console_shots \
  node scripts/ui/support_console_flow.mjs
RC=$?

kill $SERVE_PID 2>/dev/null
exit $RC
