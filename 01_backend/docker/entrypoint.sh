#!/bin/sh
set -e

echo "╔══════════════════════════════════════╗"
echo "║   أميال باي — بدء التهيئة           ║"
echo "╚══════════════════════════════════════╝"

cd /var/www/html

# ── انتظر MySQL ────────────────────────────────────────
echo "⏳ انتظار MySQL..."
until mysql -h"$DB_HOST" -u"$DB_USERNAME" -p"$DB_PASSWORD" -e "SELECT 1" >/dev/null 2>&1; do
    sleep 2
done
echo "✓ MySQL جاهز"

# ── إنشاء APP_KEY إن لم يوجد ──────────────────────────
if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:" ]; then
    echo "🔑 إنشاء APP_KEY..."
    php artisan key:generate --force
fi

# ── تطبيق الـ Migrations ──────────────────────────────
echo "🗄️  تطبيق الـ migrations..."
php artisan migrate --force

# ── تشغيل الـ Seeders (بيانات تجريبية) ───────────────
if [ "${RUN_SEEDERS:-false}" = "true" ]; then
    echo "🌱 تشغيل الـ seeders..."
    php artisan db:seed --class=DemoDataSeeder --force 2>/dev/null || true
    php artisan db:seed --class=SettlementPartnerSeeder --force 2>/dev/null || true
    php artisan db:seed --class=BillProvidersStubSeeder --force 2>/dev/null || true
fi

# ── ربط Storage ──────────────────────────────────────
php artisan storage:link --force 2>/dev/null || true

# ── Cache ─────────────────────────────────────────────
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "✅ التهيئة اكتملت — يبدأ Supervisor..."
exec /usr/bin/supervisord -n -c /etc/supervisord.conf
