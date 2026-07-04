#!/usr/bin/env bash
# AMIAL-UI-ADMIN-001 — يُشغّل التطبيق ثمّ يختبر تدفّق لوحة الإدارة عبر Chromium حقيقي.
set -uo pipefail
cd "$(dirname "$0")/../.."

export DB_DATABASE="${DB_DATABASE:-amial_conc}"
export SESSION_DRIVER=database CACHE_STORE=database CACHE_DRIVER=database QUEUE_CONNECTION=database
export AMIAL_DISABLE_ADMIN_CAPTCHA=true   # وضع العرض/الاختبار فقط
export NODE_PATH=/opt/node22/lib/node_modules
export PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers
PORT=8199

echo "═══ تهيئة + تشغيل التطبيق (كابتشا معطّلة — وضع اختبار) ═══"
php artisan config:clear >/dev/null 2>&1
php artisan migrate:fresh --force >/dev/null 2>&1
php artisan db:seed --class=DemoDataSeeder --force >/dev/null 2>&1
php artisan passport:install --no-interaction >/dev/null 2>&1

php artisan serve --host=127.0.0.1 --port=$PORT >/tmp/amial_admin_serve.log 2>&1 &
SERVE_PID=$!
for i in $(seq 1 15); do curl -s -o /dev/null "http://127.0.0.1:$PORT/admin/auth/login" && break; sleep 1; done

BASE_URL="http://127.0.0.1:$PORT" ADMIN_PHONE=967700000000 ADMIN_PASS='Admin@2026' \
  node scripts/ui/admin_flow.mjs
RC=$?

kill $SERVE_PID 2>/dev/null
exit $RC
