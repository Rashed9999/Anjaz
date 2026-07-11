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

# ── APP_KEY: يجب أن يصل لعمّال php-fpm (لا للبيئة فقط) ──────────────
# جذر مشكلة فشل الدخول: طلب الدخول يحلّ حارس Passport ويُصدر رمزاً، وكلاهما
# يحتاج APP_KEY (المُعمّي). عمّال php-fpm يعملون بـ clear_env=yes افتراضياً
# فلا يرثون متغيّراً «مُصدَّراً» من هذا السكربت → env('APP_KEY') = null →
# «No application encryption key» → 500 (صفحة HTML) → التطبيق يُظهر «فشل الدخول».
#
# الحلّ الحاسم: نكتب APP_KEY في ملفّ .env فعلي. Laravel/Dotenv يُحمّله في كلّ
# عامل مهما كان clear_env، ولا يطمس متغيّرات Railway (DB_*) لأنّها ليست فيه.
ENV_FILE="/var/www/html/.env"
if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:" ]; then
    echo "🔑 توليد APP_KEY (اضبط APP_KEY كمتغيّر بيئة ثابت لإبقاء الرموز صالحة عبر النشر)..."
    APP_KEY=$(php artisan key:generate --show 2>/dev/null || echo "")
fi
if [ -n "$APP_KEY" ] && [ "$APP_KEY" != "base64:" ]; then
    export APP_KEY
    # اكتب/حدّث السطر في .env (يقرؤه كلّ عامل php-fpm عبر Dotenv)
    touch "$ENV_FILE"
    if grep -q '^APP_KEY=' "$ENV_FILE" 2>/dev/null; then
        sed -i "s|^APP_KEY=.*|APP_KEY=${APP_KEY}|" "$ENV_FILE"
    else
        printf 'APP_KEY=%s\n' "$APP_KEY" >> "$ENV_FILE"
    fi
    # مفاتيح PII كذلك في .env (احتياط لو لم تُضبط كمتغيّرات Railway) —
    # ثابتة من config/amial.php افتراضياً، لكن تثبيتها هنا يمنع أيّ التباس.
    for pair in "AMIAL_PII_ENCRYPTION_KEY=${AMIAL_PII_ENCRYPTION_KEY:-}" \
                "AMIAL_PII_BLIND_INDEX_KEY=${AMIAL_PII_BLIND_INDEX_KEY:-}"; do
        k="${pair%%=*}"; v="${pair#*=}"
        [ -z "$v" ] && continue
        if grep -q "^${k}=" "$ENV_FILE" 2>/dev/null; then
            sed -i "s|^${k}=.*|${k}=${v}|" "$ENV_FILE"
        else
            printf '%s=%s\n' "$k" "$v" >> "$ENV_FILE"
        fi
    done

    # ── الكاش إلى قاعدة البيانات (لا ملفّات) ──────────────────────────
    # سبب «fopen(storage/framework/cache/data/…): No such file or directory»:
    # مُشغّل الكاش الافتراضي = file، وعلى Railway لا يستطيع إنشاء المجلّدات
    # الفرعية للكاش، فينهار throttle (يحرس كل طلب مصادَق) → تفشل كل الخدمات.
    # الحلّ الجذري: كاش قاعدة البيانات (جدول cache موجود عبر migration).
    for pair in "CACHE_DRIVER=${CACHE_DRIVER:-database}" \
                "CACHE_STORE=${CACHE_STORE:-database}"; do
        k="${pair%%=*}"; v="${pair#*=}"
        if grep -q "^${k}=" "$ENV_FILE" 2>/dev/null; then
            sed -i "s|^${k}=.*|${k}=${v}|" "$ENV_FILE"
        else
            printf '%s=%s\n' "$k" "$v" >> "$ENV_FILE"
        fi
    done

    echo "✓ APP_KEY + كاش قاعدة البيانات مكتوبة في .env — يقرؤها كلّ عامل php-fpm"
else
    echo "⚠️  تعذّر توليد APP_KEY — اضبطه يدوياً كمتغيّر بيئة Railway."
fi

# ── مفاتيح Passport (RSA) — ملفّات لا تحتاج قاعدة بيانات ──────────────
# نولّدها هنا (قبل بدء الخدمة) لا في خلفية مربوطة بالـ DB، فتكون جاهزة قبل
# أوّل طلب دخول (حلّ حارس api يحمّل المفتاح العامّ؛ غيابه = 500). إن وُجدت
# (مضبوطة كمتغيّرات بيئة) لا تُلمَس.
if [ ! -f storage/oauth-private.key ] || [ ! -f storage/oauth-public.key ]; then
    echo "🔑 توليد مفاتيح Passport (RSA) قبل بدء الخدمة..."
    php artisan passport:keys --force 2>&1 | tail -1 || true
fi
chmod 600 storage/oauth-private.key storage/oauth-public.key 2>/dev/null || true
chown www-data:www-data storage/oauth-*.key 2>/dev/null || true

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
        # RESET_DB=true: مسح القاعدة وإعادة بنائها (لمرّة واحدة) — يحلّ فشل
        # الدخول الناتج عن بيانات تجريبية بُذرت بمفاتيح قديمة. أزِله بعدها.
        MIGRATE_CMD="migrate --force"
        if [ "${RESET_DB:-false}" = "true" ]; then
            MIGRATE_CMD="migrate:fresh --force"
            echo "♻️  [خلفية] RESET_DB=true → مسح وإعادة بناء القاعدة بالكامل"
        fi
        DB_OK=0
        i=0
        while [ "$i" -lt 40 ]; do
            i=$((i+1))
            if php artisan $MIGRATE_CMD 2>&1; then
                DB_OK=1
                echo "✓ [خلفية] الجداول جاهزة — المحاولة $i"
                break
            fi
            echo "… [خلفية] القاعدة غير جاهزة بعد (محاولة $i) — إعادة خلال 4 ثوانٍ"
            sleep 4
        done

        # AMIAL-FIX: مفاتيح Passport (RSA) تُولَّد الآن في المقدّمة قبل بدء الخدمة
        # (أعلى). هنا نُنشئ فقط «عميل الوصول الشخصي» لأنّه يحتاج قاعدة البيانات.
        # Passport 12: عميل الوصول الشخصي في جدول oauth_personal_access_clients.
        if [ "$DB_OK" -eq 1 ]; then
            HAS_PAC=$(php artisan tinker --execute="try { echo (int) \DB::table('oauth_personal_access_clients')->count(); } catch (\Throwable \$e) { echo 0; }" 2>/dev/null | tail -1 | tr -dc '0-9')
            if [ "${HAS_PAC:-0}" = "0" ]; then
                echo "🔑 [خلفية] إنشاء عميل الوصول الشخصي لـ Passport..."
                php artisan passport:client --personal --no-interaction --name="Amial Personal Access" 2>&1 | tail -2 || true
            fi
        fi

        if [ "$DB_OK" -eq 1 ] && [ "${RUN_SEEDERS:-false}" = "true" ]; then
            echo "🌱 [خلفية] تشغيل الـ seeders..."
            php artisan db:seed --class=DemoDataSeeder --force 2>/dev/null || true
            php artisan db:seed --class=FeatureFlagsSeeder --force 2>/dev/null || true
            php artisan db:seed --class=AmlDefaultRulesSeeder --force 2>/dev/null || true
            php artisan db:seed --class=SettlementPartnerSeeder --force 2>/dev/null || true
            php artisan db:seed --class=BillProvidersStubSeeder --force 2>/dev/null || true
        fi
        # AMIAL-DEMO-001: يضمن عميلاً تجريبياً صحيحاً ويختبر الدخول (النتيجة
        # ✅/❌ تظهر في سجلّ Railway مباشرةً — تشخيص قاطع).
        if [ "$DB_OK" -eq 1 ]; then
            echo "🔎 [خلفية] ضمان واختبار الحساب التجريبي..."
            php artisan amial:ensure-demo 2>&1 || true
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
