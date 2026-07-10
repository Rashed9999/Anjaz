#!/bin/sh
set -e

echo "╔══════════════════════════════════════╗"
echo "║   أميال باي — بدء التهيئة           ║"
echo "╚══════════════════════════════════════╝"

cd /var/www/html

# ── منافذ إصغاء nginx: منفذ Railway ($PORT) + الدومين (9000) + 8080 ──
# نستمع على كل المنافذ المحتملة (IPv4 و IPv6) فينتهي أي لبس بين منفذ
# الفحص الصحّي ومنفذ الدومين. php-fpm على 9001 فلا تعارض.
PORT="${PORT:-8080}"
echo "🔌 nginx يستمع على المنافذ المحتملة: ${PORT} + 9000 + 8080 (IPv4/IPv6)"
: > /etc/nginx/listen.conf
for p in "$PORT" 9000 8080; do
    if ! grep -q "listen ${p};" /etc/nginx/listen.conf 2>/dev/null; then
        printf '        listen %s;\n        listen [::]:%s;\n' "$p" "$p" >> /etc/nginx/listen.conf
    fi
done

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

# ── مفاتيح تشفير PII ──────────────────────────────────
# AMIAL-FIX: أُزيل التوليد العشوائي (كان يكسر فهارس البحث المُعمّاة كل نشر
# فيفشل الدخول). المفاتيح الآن ثابتة من config/amial.php (أو متغيّرات بيئة).

# سجلّ الأخطاء إلى stderr ليظهر في سجلّات Railway (تشخيص أسهل)
export LOG_CHANNEL="${LOG_CHANNEL:-stderr}"

# ── ضمان وجود مجلّدات storage وقابليّتها للكتابة (www-data) ──────────
# سبب 500: throttle يكتب كاش ملفّات في storage/framework/cache/data لكن
# المجلّد غير قابل للكتابة → fopen يفشل. نُنشئها ونمنحها صلاحية الكتابة.
mkdir -p storage/framework/cache/data storage/framework/sessions \
         storage/framework/views storage/logs bootstrap/cache
chmod -R 777 storage bootstrap/cache 2>/dev/null || true
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# ── تنظيف أي كاش قديم من بناء الصورة (يقرأ Laravel المفاتيح المُصدَّرة
#    مباشرةً من البيئة بدل كاش قديم بُني قبل توليد APP_KEY — يمنع 500) ──
php artisan config:clear 2>/dev/null || true
php artisan route:clear 2>/dev/null || true
php artisan view:clear 2>/dev/null || true

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
