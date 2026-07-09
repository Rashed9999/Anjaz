#!/bin/sh
set -e

echo "╔══════════════════════════════════════╗"
echo "║   أميال باي — بدء التهيئة           ║"
echo "╚══════════════════════════════════════╝"

cd /var/www/html

# ── ربط منفذ Railway الديناميكي ($PORT) — nginx يستمع عليه ─────────
# محلياً لا يُضبط PORT فيبقى 80 (متوافق مع docker-compose).
PORT="${PORT:-80}"
echo "🔌 nginx سيستمع على المنفذ: ${PORT}"
sed -i "s/listen 80;/listen ${PORT};/" /etc/nginx/nginx.conf || true

# ── إنشاء APP_KEY إن لم يوجد ──────────────────────────
# على Railway: يُفضّل ضبط APP_KEY كمتغيّر بيئة ثابت (وإلا يُولَّد مؤقتاً).
if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:" ]; then
    echo "🔑 إنشاء APP_KEY مؤقّت (اضبطه كمتغيّر بيئة للثبات)..."
    php artisan key:generate --force 2>/dev/null || true
fi

# ── انتظر قاعدة البيانات (بمهلة — لا تعليق لانهائي) ────────────────
if [ -n "$DB_HOST" ]; then
    echo "⏳ انتظار قاعدة البيانات على ${DB_HOST}..."
    i=0
    until mysql -h"$DB_HOST" -P"${DB_PORT:-3306}" -u"$DB_USERNAME" -p"$DB_PASSWORD" -e "SELECT 1" >/dev/null 2>&1; do
        i=$((i+1))
        if [ "$i" -ge 30 ]; then
            echo "⚠️  تعذّر الاتصال بقاعدة البيانات بعد 60 ثانية — سنُكمل ليبدأ الخادم (تحقّق من متغيّرات DB)."
            break
        fi
        sleep 2
    done
    [ "$i" -lt 30 ] && echo "✓ قاعدة البيانات جاهزة"
else
    echo "⚠️  DB_HOST غير مضبوط — تخطّي انتظار قاعدة البيانات."
fi

# ── تطبيق الـ Migrations (متسامح — لا يُفشل بدء الخادم) ────────────
echo "🗄️  تطبيق الـ migrations..."
php artisan migrate --force 2>&1 || echo "⚠️  فشلت الـ migrations — سيبدأ الخادم لعرض الأخطاء (تحقّق من قاعدة البيانات)."

# ── تشغيل الـ Seeders (بيانات تجريبية) عند الطلب ─────────────────
if [ "${RUN_SEEDERS:-false}" = "true" ]; then
    echo "🌱 تشغيل الـ seeders..."
    php artisan db:seed --class=DemoDataSeeder --force 2>/dev/null || true
    php artisan db:seed --class=FeatureFlagsSeeder --force 2>/dev/null || true
    php artisan db:seed --class=AmlDefaultRulesSeeder --force 2>/dev/null || true
    php artisan db:seed --class=SettlementPartnerSeeder --force 2>/dev/null || true
    php artisan db:seed --class=BillProvidersStubSeeder --force 2>/dev/null || true
fi

# ── ربط Storage ──────────────────────────────────────
php artisan storage:link --force 2>/dev/null || true

# ── Cache (متسامح) ────────────────────────────────────
php artisan config:cache 2>/dev/null || true
php artisan route:cache 2>/dev/null || true
php artisan view:cache 2>/dev/null || true

echo "✅ التهيئة اكتملت — يبدأ Supervisor على المنفذ ${PORT}..."
exec /usr/bin/supervisord -n -c /etc/supervisord.conf
