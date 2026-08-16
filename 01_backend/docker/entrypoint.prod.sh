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

# ── AMIAL-LEDGER-OPENING-002: إدخال المحافظ القائمة في دفتر الأستاذ ────
#
# صار ترحيلُ القيود إلزامياً: أي تحويل لا يستطيع الدفتر تفسيره يُرفض. ومحافظ
# الإنتاج القائمة مموّلة من قبل أن يبدأ الدفتر بالعمل، فهو يراها صفراً —
# وأوّل خصمٍ من أيٍّ منها يُرفض بـ«الرصيد لا يكفي».
#
# **ولماذا هنا لا في تعليمات النشر؟**
# تركتُه أوّل مرّة أمراً يدوياً في رسالةٍ إلى المشغّل — وأمرٌ يجب تذكّره بعد
# كل نشرة سيُنسى مرّةً، ويومَها تتوقّف التحويلات كلّها. وهذا نمطُ العطل الذي
# نطارده في هذا المشروع بعينه: شيءٌ مبنيّ وغير موصول.
#
# وهو آمن للتكرار: كل محفظة لها مفتاح تفرّد واحد، فتشغيله في كل إقلاع لا
# يُضاعف شيئاً ولا يمسّ محفظةً دخلت الدفتر أصلاً.
echo "📒 إدخال المحافظ القائمة في دفتر الأستاذ..."
php artisan amial:ledger-backfill 2>&1 || {
    echo "⚠️  فشل ترحيل الدفتر — التحويلات من المحافظ القديمة ستُرفض."
    echo "    شغّله يدوياً: php artisan amial:ledger-backfill"
}

# ── AMIAL-VERTICAL-BOOTSTRAP-001: سجلّ القطاع للحسابات القائمة ─────────
#
# حسابُ محطّةٍ أُنشئ من اللوحة قبل هذا الإصلاح بلا صفٍّ في `fuel_stations`،
# فيقرأ صاحبُه في التطبيق «لا توجد محطة مرتبطة بهذا الحساب» — والحسابُ
# أُنشئ محطّةً. وإصلاحُ المنبع يحمي ما بعده ولا يشفي ما قبله.
#
# آمنُ التكرار: يمرّ على السليم بلا أن يمسّه.
echo "🏭 تهيئة سجلّات القطاعات للحسابات القائمة..."
php artisan amial:heal-verticals 2>&1 || {
    echo "⚠️  فشلت تهيئة القطاعات — شاشاتُ المحطة/الصيدلية/الجملة قد ترفض."
    echo "    شغّله يدوياً: php artisan amial:heal-verticals"
}

# ── AMIAL-PDF-DURABLE-001: مجلدات التخزين تُهيَّأ هنا لا في الصورة ─────
#
# `storage/app` مُثبَّت عليه volume دائم (amial_storage_prod). و volume
# يُغطّي ما في الصورة تحته: أي مجلّد أنشأه Dockerfile داخل storage/app لا
# يراه التطبيق بعد التثبيت، والمجلدات الجديدة لا تُنقل إلى volume قائم أبداً.
#
# لهذا كانت مشكلة الـ PDF تعود بعد كل «إصلاح»: الإصلاح يُوضع في الصورة
# فيُحجب. mPDF يحتاج tempDir قابلاً للكتابة، فإن غاب رمى استثناءً وسط توليد
# الإيصال — فيبدو كأن «خدمة PDF انقطعت» وليس في الأمر خدمة أصلاً.
#
# الموضع الصحيح هنا: بعد التثبيت، في كل إقلاع، وهو رخيص ولا يُتلف موجوداً.
echo "📁 تهيئة مجلدات التخزين..."
mkdir -p storage/app/mpdf \
         storage/app/private \
         storage/app/receipts storage/app/documents \
         storage/app/signatures \
         storage/app/public \
         storage/logs \
         storage/framework/cache/data \
         storage/framework/sessions \
         storage/framework/views \
         bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

# فحص فعليّ لا افتراض: نكتب ملفاً بهوية www-data ونقرؤه.
if su -s /bin/sh www-data -c 'touch storage/app/mpdf/.probe' 2>/dev/null; then
    rm -f storage/app/mpdf/.probe
    echo "✓ مجلدات التخزين جاهزة وقابلة للكتابة"
else
    echo "⚠️  تحذير: www-data لا يستطيع الكتابة في storage/app/mpdf"
    echo "   توليد ملفات PDF سيفشل. افحص ملكية الـ volume."
fi

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

# ── AMIAL-PROD-READINESS-001: ختمُ لحظة الإقلاع ──────────────────────
#
# يقرؤه `HealthCheckController::deployProbe` لنافذة سماح الإقلاع.
# **وهو هنا وفي `entrypoint.sh` معاً عن قصد:** الملفّان تباعدا مرّتين من
# قبل (حاجزُ APP_DEBUG ومجلّداتُ PDF)، وكلتاهما كلّفت عطلاً. الحارس:
# `DeploymentProbeGuardTest::the_deployed_entrypoint_stamps_the_boot_time`.
#
# وهذه الهجرةُ تجري في المقدّمة هنا لا في الخلفيّة، فالنافذةُ احتياطٌ لا
# حاجةٌ — لكنّ غيابَ الملفّ يجعل المسبارَ صارماً من الثانية الأولى، وهو ما
# يُسقط الإقلاعَ قبل أن يقوم php-fpm.
date -u +%s > /tmp/amial-boot-epoch 2>/dev/null || true
chmod 644 /tmp/amial-boot-epoch 2>/dev/null || true

echo "✅ التهيئة اكتملت — يبدأ Supervisor..."
exec /usr/bin/supervisord -n -c /etc/supervisord.conf
