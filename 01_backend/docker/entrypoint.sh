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
if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:" ]; then
    echo "🔑 إنشاء APP_KEY مؤقّت (اضبطه كمتغيّر بيئة للثبات)..."
    php artisan key:generate --force 2>/dev/null || true
fi

# ── Cache سريع (لا يتّصل بقاعدة البيانات) ─────────────────────────
php artisan config:cache 2>/dev/null || true
php artisan route:cache 2>/dev/null || true
php artisan view:cache 2>/dev/null || true

# ── تهيئة قاعدة البيانات في الخلفية (لا تُؤخّر بدء nginx) ──────────
# مهم: Railway يفحص الصحّة على /health/liveness فور الإقلاع. لذلك نبدأ
# nginx فوراً، ونؤجّل انتظار قاعدة البيانات + migrations للخلفية حتى لا
# يفشل الفحص الصحّي إن كانت القاعدة غير جاهزة بعد.
(
    if [ -n "$DB_HOST" ]; then
        echo "⏳ [خلفية] انتظار قاعدة البيانات على ${DB_HOST}..."
        i=0
        until mysql -h"$DB_HOST" -P"${DB_PORT:-3306}" -u"$DB_USERNAME" -p"$DB_PASSWORD" -e "SELECT 1" >/dev/null 2>&1; do
            i=$((i+1))
            if [ "$i" -ge 60 ]; then
                echo "⚠️  [خلفية] تعذّر الاتصال بقاعدة البيانات بعد دقيقتين — تحقّق من متغيّرات DB."
                exit 0
            fi
            sleep 2
        done
        echo "✓ [خلفية] قاعدة البيانات جاهزة — تطبيق الـ migrations..."
        php artisan migrate --force 2>&1 || echo "⚠️  [خلفية] فشلت الـ migrations."

        if [ "${RUN_SEEDERS:-false}" = "true" ]; then
            echo "🌱 [خلفية] تشغيل الـ seeders..."
            php artisan db:seed --class=DemoDataSeeder --force 2>/dev/null || true
            php artisan db:seed --class=FeatureFlagsSeeder --force 2>/dev/null || true
            php artisan db:seed --class=AmlDefaultRulesSeeder --force 2>/dev/null || true
            php artisan db:seed --class=SettlementPartnerSeeder --force 2>/dev/null || true
            php artisan db:seed --class=BillProvidersStubSeeder --force 2>/dev/null || true
        fi
        php artisan storage:link --force 2>/dev/null || true
        echo "✅ [خلفية] تهيئة قاعدة البيانات اكتملت."
    else
        echo "⚠️  [خلفية] DB_HOST غير مضبوط — تخطّي قاعدة البيانات (أضِف MySQL واربط المتغيّرات)."
    fi
) &

# ── بدء الخادم فوراً (nginx على $PORT) ليمرّ الفحص الصحّي ─────────
echo "✅ يبدأ Supervisor فوراً على المنفذ ${PORT} (تهيئة القاعدة تجري في الخلفية)..."
exec /usr/bin/supervisord -n -c /etc/supervisord.conf
