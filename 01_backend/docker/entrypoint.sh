#!/bin/sh
set -e

echo "╔══════════════════════════════════════╗"
echo "║   أميال باي — بدء التهيئة           ║"
echo "╚══════════════════════════════════════╝"

cd /var/www/html

# ── ربط منفذ Railway الديناميكي ($PORT) — nginx يستمع عليه ─────────
# محلياً لا يُضبط PORT فيبقى 80 (متوافق مع docker-compose).
PORT="${PORT:-80}"
echo "🔌 nginx سيستمع على المنفذ: ${PORT} (IPv4 + IPv6)"
# مهم لـ Railway: يوجّه الطلبات العامة عبر IPv6، لذا نُصغي على IPv4 و IPv6 معاً.
sed -i "s/listen 80;/listen ${PORT};/" /etc/nginx/nginx.conf || true
sed -i "s/listen \[::\]:80;/listen [::]:${PORT};/" /etc/nginx/nginx.conf || true

# ── إنشاء APP_KEY إن لم يوجد ──────────────────────────
# مهم على Railway: لا يوجد ملف .env، لذا key:generate --force يفشل ويترك
# المفتاح فارغاً فيُرجع التطبيق 500 (ويفشل الفحص الصحّي). الحل: --show
# يطبع المفتاح دون ملف، ونُصدّره للبيئة فيرثه php-fpm عبر supervisord.
if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:" ]; then
    echo "🔑 توليد APP_KEY مؤقّت (اضبطه كمتغيّر بيئة ثابت للإنتاج)..."
    GEN_KEY=$(php artisan key:generate --show 2>/dev/null || echo "")
    if [ -n "$GEN_KEY" ]; then
        export APP_KEY="$GEN_KEY"
        echo "✓ APP_KEY جاهز في البيئة"
    else
        echo "⚠️  تعذّر توليد APP_KEY — اضبطه يدوياً كمتغيّر بيئة."
    fi
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
        # نستخدم اتصال Laravel نفسه (PDO) لا أداة mysql الطرفية — أوثق مع
        # شبكة Railway الداخلية (IPv6). نُعيد المحاولة حتى تجهز القاعدة.
        echo "⏳ [خلفية] تهيئة قاعدة البيانات عبر PDO (${DB_HOST})..."
        DB_OK=0
        i=0
        while [ "$i" -lt 40 ]; do
            i=$((i+1))
            if php artisan migrate --force 2>&1; then
                DB_OK=1
                echo "✓ [خلفية] الجداول طُبّقت (migrations) — المحاولة $i"
                break
            fi
            echo "… [خلفية] القاعدة غير جاهزة بعد (محاولة $i) — إعادة خلال 4 ثوانٍ"
            sleep 4
        done

        if [ "$DB_OK" -eq 1 ] && [ "${RUN_SEEDERS:-false}" = "true" ]; then
            echo "🌱 [خلفية] تشغيل الـ seeders..."
            php artisan db:seed --class=DemoDataSeeder --force 2>/dev/null || true
            php artisan db:seed --class=FeatureFlagsSeeder --force 2>/dev/null || true
            php artisan db:seed --class=AmlDefaultRulesSeeder --force 2>/dev/null || true
            php artisan db:seed --class=SettlementPartnerSeeder --force 2>/dev/null || true
            php artisan db:seed --class=BillProvidersStubSeeder --force 2>/dev/null || true
        fi
        php artisan storage:link --force 2>/dev/null || true
        if [ "$DB_OK" -eq 1 ]; then
            echo "✅ [خلفية] تهيئة قاعدة البيانات اكتملت."
        else
            echo "⚠️  [خلفية] تعذّر الاتصال بقاعدة البيانات بعد كل المحاولات — تحقّق أن خدمة MySQL تعمل وأن المتغيّرات مربوطة."
        fi
    else
        echo "⚠️  [خلفية] DB_HOST غير مضبوط — تخطّي قاعدة البيانات (أضِف MySQL واربط المتغيّرات)."
    fi
) &

# ── بدء الخادم فوراً (nginx على $PORT) ليمرّ الفحص الصحّي ─────────
echo "✅ يبدأ Supervisor فوراً على المنفذ ${PORT} (تهيئة القاعدة تجري في الخلفية)..."
exec /usr/bin/supervisord -n -c /etc/supervisord.conf
