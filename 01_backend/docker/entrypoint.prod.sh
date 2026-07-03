#!/bin/sh
set -e

echo "╔════════════════════════════════════════╗"
echo "║   أميال باي — تهيئة الإنتاج            ║"
echo "╚════════════════════════════════════════╝"

cd /var/www/html

# ── حماية: رفض التشغيل لو APP_DEBUG=true أو APP_ENV غير production ──
# AMIAL-DEVOPS-004 — عرض أخطاء PHP الكامل (stack traces) في الإنتاج
# يُسرّب أسراراً (مسارات، استعلامات DB) لأيّ مستخدم يستقبل خطأ 500.
if [ "$APP_DEBUG" = "true" ]; then
    echo "❌ خطأ فادح: APP_DEBUG=true في بيئة إنتاج. أوقف التشغيل."
    echo "   عدِّل .env.production: APP_DEBUG=false"
    exit 1
fi

# ── انتظار MySQL ─────────────────────────────────────────
echo "⏳ انتظار MySQL..."
RETRIES=30
until mysql -h"$DB_HOST" -u"$DB_USERNAME" -p"$DB_PASSWORD" -e "SELECT 1" >/dev/null 2>&1; do
    RETRIES=$((RETRIES - 1))
    if [ "$RETRIES" -le 0 ]; then
        echo "❌ تعذّر الاتصال بـ MySQL بعد عدّة محاولات."
        exit 1
    fi
    sleep 2
done
echo "✓ MySQL جاهز"

# ── APP_KEY يجب أن يكون مُعدّاً سلفاً في الإنتاج (لا يُنشأ تلقائياً) ──
if [ -z "$APP_KEY" ]; then
    echo "❌ خطأ فادح: APP_KEY غير مضبوط."
    echo "   ولِّده مرّة واحدة بأمان: php artisan key:generate --show"
    echo "   ثمّ ثبِّته في .env.production ولا تُغيِّره لاحقاً"
    echo "   (تغييره يُفقِد القدرة على فكّ تشفير البيانات الحسّاسة)."
    exit 1
fi

# ── Migrations (بلا seeders أبداً في الإنتاج) ────────────
echo "🗄️  تطبيق الـ migrations..."
php artisan migrate --force

# ── AMIAL-FIX: مفاتيح Passport (مُستثناة من الصورة) — تُولَّد مرّة وتُحفَظ ──
# نُفضّل حجم مُثبَّت (mounted secret/volume). إن غابت، نولّدها (تحتاج تثبيت
# storage على volume دائم حتى تبقى الرموز صالحة عبر إعادات التشغيل).
if [ ! -f storage/oauth-private.key ]; then
    echo "🔑 توليد مفاتيح Passport (لم تكن موجودة)..."
    php artisan passport:keys --force
fi

php artisan storage:link --force 2>/dev/null || true

# ── Cache للإنتاج ─────────────────────────────────────────
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "✅ التهيئة اكتملت — يبدأ Supervisor..."
exec /usr/bin/supervisord -n -c /etc/supervisord.conf
