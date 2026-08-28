#!/bin/sh
set -e

echo "╔══════════════════════════════════════╗"
echo "║   أميال باي — بدء التهيئة           ║"
echo "╚══════════════════════════════════════╝"

cd /var/www/html

# ── AMIAL-CLOCK-GUARD-001: ساعةُ الخادم تُفحص قبل أيّ شيء ────────────
#
# ساعةٌ خاطئة لا تشتكي: النظام يعمل ويُصدر أرقاماً، وكلُّ ما يُبنى عليها
# كاذب — أوقاتُ العمليّات، وسلسلةُ ساعات العمل، وصلاحيّةُ رمز التحقّق،
# وحدودُ اليوم في التسوية. وشهادةُ Let's Encrypt تُقرأ «لم تبدأ بعد».
#
# وقعت مرّتين (٣٥ ساعةً ثمّ ٤٤) ولم تُكتشف إلّا حين اصطدم بها مستخدم.
#
# ولا شبكةَ مضمونةٌ هنا لسؤال خادم زمن. لكنّ حقيقةً واحدةً تكفي: **لا
# يمكن أن يكون الآن أقدمَ من لحظة بناء هذه الصورة.**
if [ -r /etc/amial-build-epoch ]; then
    BUILT_AT=$(cat /etc/amial-build-epoch 2>/dev/null || echo 0)
    NOW=$(date -u +%s)
    if [ "${BUILT_AT:-0}" -gt 0 ] && [ "$NOW" -lt "$BUILT_AT" ]; then
        BEHIND=$(( (BUILT_AT - NOW) / 3600 ))
        echo "╔══════════════════════════════════════════════════════════╗"
        echo "║  ⛔ ساعةُ الخادم خاطئة — والنظام سيعمل وهو يكذب          ║"
        echo "╚══════════════════════════════════════════════════════════╝"
        echo "   الآن حسب الخادم : $(date -u '+%Y-%m-%d %H:%M:%S') UTC"
        echo "   وبُنيت الصورة في: $(date -u -d "@${BUILT_AT}" '+%Y-%m-%d %H:%M:%S' 2>/dev/null || echo "${BUILT_AT}") UTC"
        echo "   أي أنّ الساعة متأخّرةٌ ${BEHIND} ساعةً على الأقلّ."
        echo ""
        echo "   ما ينكسر: الدخول (٤١٩) · شهادة HTTPS وتجديدها · أوقات"
        echo "   العمليّات · سلسلة ساعات العمل · صلاحية رمز التحقّق."
        echo ""
        echo "   الإصلاح على المضيف لا في الحاوية:"
        echo "       timedatectl set-ntp true"
        echo "   أو أعِد تشغيل الخادم."
        echo "══════════════════════════════════════════════════════════════"
    else
        echo "🕐 ساعة الخادم: $(date -u '+%Y-%m-%d %H:%M:%S') UTC — لا تأخّر مُكتشَف"
    fi
fi

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
         storage/framework/views storage/logs storage/fonts bootstrap/cache

# AMIAL-PDF-DURABLE-001: مجلّدات storage/app تُنشأ هنا لا في الصورة.
#
# حين يُثبَّت volume على storage/app فإنه يحجب ما أنشأته الصورة تحته، ولا
# يستقبل مجلدات جديدة تُضاف إليها لاحقاً. mPDF يحتاج tempDir قابلاً
# للكتابة، فإن غاب رمى استثناءً وسط توليد الإيصال — فيبدو كأن «خدمة PDF
# انقطعت» ولا خدمة في الأمر أصلاً.
#
# كان هذا الإصلاح في entrypoint.prod.sh وحده، وهذا الملفّ هو المُستعمل
# فعلاً في النشر الحالي (Dockerfile لا Dockerfile.prod) — فلم يكن يصل.
mkdir -p storage/app/mpdf storage/app/private \
         storage/app/receipts storage/app/documents storage/app/signatures storage/app/public
# AMIAL-FIX(LOG-PERMS): ننشئ laravel.log مقدّماً بملكية www-data وصلاحية 666.
# كان يُنشأ لاحقاً بملكية root (أوامر artisan الخلفية تعمل كـroot) فيعجز
# عامل php-fpm (www-data) عن الكتابة → «Permission denied» يحوّل أي خطأ
# إلى 500 قاسٍ (شوهد في التحويل وتنزيل PDF).
touch storage/logs/laravel.log 2>/dev/null || true
chmod -R 777 storage bootstrap/cache 2>/dev/null || true
chmod 666 storage/logs/laravel.log 2>/dev/null || true
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# فحص فعليّ لا افتراض: نكتب بهوية www-data ونقرأ. سطر واحد في سجلّ
# الإقلاع يوفّر ساعةً من التخمين لاحقاً حين «يتعطّل الـ PDF».
if su -s /bin/sh www-data -c 'touch storage/app/mpdf/.probe' 2>/dev/null; then
    rm -f storage/app/mpdf/.probe
    echo "✓ مجلّدات storage/app جاهزة (توليد PDF ممكن)"
else
    echo "⚠️  www-data لا يستطيع الكتابة في storage/app/mpdf — توليد PDF سيفشل."
    echo "   شخّص على الخادم: php artisan amial:pdf-doctor"
fi

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
    # AMIAL-FIX(LOG-PERMS): أوامر artisan هنا تعمل كـroot — نوجّه سجلّها إلى
    # stderr كي لا تُنشئ laravel.log بملكية root فتكسر كتابة عمّال php-fpm.
    export LOG_CHANNEL=stderr
    if [ -n "$DB_HOST" ]; then
        # نستخدم اتصال Laravel نفسه (PDO) لا أداة mysql الطرفية — أوثق مع
        # شبكة Railway الداخلية (IPv6). نُعيد المحاولة حتى تجهز القاعدة.
        echo "⏳ [خلفية] تهيئة قاعدة البيانات عبر PDO (${DB_HOST})..."
        # ── هجرة القاعدة مع حاجز أمان ضد فقدان البيانات ─────────────
        # الافتراضي دائماً: migrate الآمن (يضيف الجداول الجديدة، لا يمسح شيئاً).
        #
        # المسح الكامل (migrate:fresh) لا يحدث إلا في حالتين واضحتين:
        #   1) RESET_DB_FORCE=true  → مسح متعمّد مفروض (حتى لو كانت هناك بيانات).
        #   2) RESET_DB=true        → يمسح فقط إذا كانت القاعدة فارغة تماماً
        #      (بناء أوّلي). إن وُجد أيّ حساب — يُتجاهل المسح تلقائياً لحماية
        #      الحسابات التي أنشأها الأدمن وتعديلاتها. هذا يمنع ضياع البيانات
        #      عند نسيان RESET_DB=true مضبوطاً في Coolify.
        MIGRATE_CMD="migrate --force"
        if [ "${RESET_DB_FORCE:-false}" = "true" ]; then
            MIGRATE_CMD="migrate:fresh --force"
            echo "♻️  [خلفية] RESET_DB_FORCE=true → مسح كامل مفروض ومتعمّد للقاعدة"
        elif [ "${RESET_DB:-false}" = "true" ]; then
            # هل القاعدة فارغة؟ (EMPTY=لا جدول users أو صفر حسابات، HASDATA=فيها
            # حسابات، UNKNOWN=تعذّر الاتصال). عند الشكّ لا نمسح إطلاقاً.
            DB_STATE=$(php artisan tinker --execute="try { echo \Illuminate\Support\Facades\Schema::hasTable('users') ? ((int)\DB::table('users')->count() > 0 ? 'HASDATA' : 'EMPTY') : 'EMPTY'; } catch (\Throwable \$e) { echo 'UNKNOWN'; }" 2>/dev/null | tail -1 | tr -dc 'A-Z')
            if [ "$DB_STATE" = "EMPTY" ]; then
                MIGRATE_CMD="migrate:fresh --force"
                echo "♻️  [خلفية] RESET_DB=true وقاعدة فارغة → بناء أوّلي كامل"
            else
                echo "🛡️  [خلفية] RESET_DB=true لكن القاعدة تحتوي بيانات (${DB_STATE:-UNKNOWN}) —"
                echo "    تمّ تجاهُل المسح تلقائياً لحماية حساباتك وتعديلاتك."
                echo "    للمسح الكامل المتعمّد فقط: اضبط RESET_DB_FORCE=true ثم أزِله بعدها."
            fi
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

        # ══════════════════════════════════════════════════════════════
        # AMIAL-AML-COVERAGE-002 — **قواعدُ الرقابة سياسةٌ لا بياناتُ عرض.**
        #
        # **الثمنُ الذي قِيس:** كانت بذرةُ قواعد AML خلف `RUN_SEEDERS=true`
        # مع بذور العرض — **وهي `false` افتراضاً**. فالإنتاجُ يُقلع بلا
        # قاعدةِ رقابةٍ واحدة، والفحصُ لا يجري على أيّ ريال.
        #
        # وفشلُها كان مبتلعاً بـ`2>/dev/null || true`، فلا أثرَ في السجلّ.
        #
        # فصارت تُشغَّل **دائماً** — وهي آمنةُ التكرار: `updateOrCreate`
        # على الرمز، **ولا تُلغي قرارَ مشغّلٍ أطفأ الظلّ عن قاعدة** (‏الظلّ
        # يُكتب عند الإنشاء فقط). وفشلُها يُقال بصوتٍ عالٍ.
        if [ "$DB_OK" -eq 1 ]; then
            echo "🛡️ [خلفية] ضمان قواعد الرقابة على غسل الأموال..."
            if php artisan db:seed --class=AmlDefaultRulesSeeder --force 2>&1 | tail -3; then
                :
            else
                echo "❌ فشل بذرُ قواعد AML — الفحصُ لن يجري على أيّ تدفّقٍ ماليّ."
            fi
        fi

        if [ "$DB_OK" -eq 1 ] && [ "${RUN_SEEDERS:-false}" = "true" ]; then
            echo "🌱 [خلفية] تشغيل الـ seeders..."
            php artisan db:seed --class=DemoDataSeeder --force 2>/dev/null || true
            php artisan db:seed --class=FeatureFlagsSeeder --force 2>/dev/null || true
            php artisan db:seed --class=SettlementPartnerSeeder --force 2>/dev/null || true
            php artisan db:seed --class=BillProvidersStubSeeder --force 2>/dev/null || true
        fi
        # AMIAL-DEMO-001: يضمن عميلاً تجريبياً صحيحاً ويختبر الدخول (النتيجة
        # ✅/❌ تظهر في سجلّ Railway مباشرةً — تشخيص قاطع).
        # ══════════════════════════════════════════════════════════════
        # AMIAL-DEMO-CREDENTIALS-001 — **هذا السطرُ هو الذي نقل قرارَ
        # التطوير إلى الإنتاج.**
        #
        # الأوامرُ الثلاثةُ أدناه تجري في **كلّ إقلاع حاوية**، وحارسُها
        # الوحيد «القاعدةُ حيّة» — لا شرطَ بيئةٍ إطلاقاً. وكانت تكتب
        # `admin@amialpay.com / Pass@2026` (والكلمةُ في المستودع)،
        # **وتُعيد ضبطَها على حسابٍ قائم** — فتغييرُ صاحب المشروع يُمحى
        # مع كلّ نشرة، بصمت.
        #
        # ولا تُنزَع من هنا: `ensure-demo-staff` هو مسارُ إنشاء الأدمن
        # الوحيد خارج بذرةٍ محروسة، **وفقدُ الوصول أسوأ من كلمةٍ معلومة**.
        #
        # فصار الحدُّ في الشيفرة لا في السطر: `App\Support\DemoAccountPolicy`
        # يُبقي **الإنشاءَ عند الغياب** دائماً، ويوقف **إعادةَ الضبط** في
        # الإنتاج، ويأخذ كلمةَ الحساب الجديد من
        # `AMIAL_BOOTSTRAP_ADMIN_PASSWORD` أو يولّدها ويطبعها هنا مرّةً.
        #
        # ولإعادة السلوك القديم كاملاً (عرضٌ أو تجربة):
        #     AMIAL_ALLOW_DEMO_ACCOUNTS=true
        # ══════════════════════════════════════════════════════════════
        if [ "$DB_OK" -eq 1 ]; then
            echo "🔎 [خلفية] ضمان واختبار الحساب التجريبي..."
            php artisan amial:ensure-demo 2>&1 || true
            # AMIAL-FIX(ADMIN-LOGIN): حسابا الأدمن والوكيل التجريبيان لم يكونا
            # يُبذران عند الإقلاع (الأمر أدناه لم يكن يُستدعى) → لوحة الويب
            # ترفض الدخول بـ«Credentials does not match». الأوامر idempotent.
            php artisan amial:ensure-demo-staff 2>&1 || true
            php artisan amial:ensure-demo-merchants 2>&1 || true
            # AMIAL-ROLE-SYNC-001: تصحيح role للحسابات القديمة (تاجر/وكيل/أدمن)
            # حتى يوجّهها التطبيق للوحة قطاعها بدل شاشة العميل. idempotent.
            php artisan amial:backfill-roles 2>&1 || true
        fi
        php artisan storage:link --force 2>/dev/null || true
        # AMIAL-FIX(LOG-PERMS): أي ملفّ أنشأته أوامر artisan أعلاه كـroot يعود
        # ملكاً لـwww-data — حزام أمان أخير لكتابة السجلّ وكاش dompdf.
        chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true
        chmod 666 /var/www/html/storage/logs/laravel.log 2>/dev/null || true
        if [ "$DB_OK" -eq 1 ]; then
            echo "✅ [خلفية] تهيئة قاعدة البيانات اكتملت."
        else
            echo "⚠️  [خلفية] تعذّر الاتصال بقاعدة البيانات بعد كل المحاولات — تحقّق أن خدمة MySQL تعمل وأن المتغيّرات مربوطة."
        fi
    else
        echo "⚠️  [خلفية] DB_HOST غير مضبوط — تخطّي قاعدة البيانات (أضِف MySQL واربط المتغيّرات)."
    fi
) &

# ══════════════════════════════════════════════════════════════════════
# AMIAL-PROD-READINESS-001 — **حاجزُ APP_DEBUG، في الملفّ المنشور هذه المرّة.**
#
# الحاجزُ كان مكتوباً في `entrypoint.prod.sh` وحدَه منذ AMIAL-DEVOPS-004،
# **والمنشورُ فعلاً هو هذا الملفّ** (`Build Pack = Dockerfile` في
# `DEPLOY_COOLIFY.md`). فلم يكن يحرس شيئاً.
#
# وهذا بعينه ما وقع من قبل مع مجلّدات PDF — التعليقُ عليه ما يزال في هذا
# الملفّ أعلاه: «كان في entrypoint.prod.sh وحده، وهذا الملفّ هو المُستعمَل
# فعلاً — فلم يكن يصل». **مرّتان، والسببُ واحد: ملفّان يتباعدان.**
#
# **وموضعُه هنا لا في الأعلى عمداً:** كلُّ ما يضبط البيئة (‏APP_KEY و.env
# والكاش) قد جرى، فالقيمةُ المفحوصةُ هي الفعليّةُ لا المبدئيّة. وهو آخرُ
# سطرٍ قبل تقديم أوّل طلب — أي أنّه **رفضُ الخدمة** لا رفضُ الإقلاع.
#
# ومنفذُ الخروج صريحٌ ومقصود: بيئةُ الديمو المحلّيّة (`docker-compose.yml`)
# تعمل بـ APP_DEBUG=true عمداً، فتضبط `AMIAL_ALLOW_DEBUG=true`. **والافتراضُ
# مغلقٌ** — من نسي الضبطَ في الإنتاج يُوقَف، لا يُسرّب.
#
# الحارس: `tests/Feature/DeploymentGateGuardTest.php`.
# ══════════════════════════════════════════════════════════════════════
EFFECTIVE_DEBUG="${APP_DEBUG:-}"
if [ -z "$EFFECTIVE_DEBUG" ] && [ -f "$ENV_FILE" ]; then
    EFFECTIVE_DEBUG=$(grep -E '^APP_DEBUG=' "$ENV_FILE" 2>/dev/null \
        | tail -1 | cut -d= -f2- | tr -d '"'"'"' \r')
fi

# ══════════════════════════════════════════════════════════════════════
# AMIAL-DEBUG-ESCAPE-001 — **والمنفذُ يُغلَق في الإنتاج.**
#
# `AMIAL_ALLOW_DEBUG=true` كان يفتح الحاجزَ **في أيّ بيئة**، بما فيها
# `APP_ENV=production`. ومنفذٌ يُفتح مرّةً لتجربةٍ عاجلة يبقى مفتوحاً:
# لا شيءَ يُغلقه، ولا شيءَ يذكّر به، **وصفحةُ الخطأ تُسرّب مساراتِ
# الملفّات ونصوصَ الاستعلامات لأيّ مستخدمٍ يستقبل ٥٠٠**.
#
# فصار المنفذُ لغيرِ الإنتاج وحدَه. وبيئةُ الديمو المحلّيّةُ لا تضبط
# `APP_ENV=production` أصلاً، فلا يمسّها هذا.
#
# **وبهذا يصير الشرطُ مضموناً بالبناء لا بالتذكُّر**: حاويةُ إنتاجٍ
# تعمل ⇒ التنقيحُ مغلقٌ حتماً. ولا يبقى «اضبطه ولا تنسَ».
# ══════════════════════════════════════════════════════════════════════
DEBUG_ESCAPE="${AMIAL_ALLOW_DEBUG:-false}"
if [ "${APP_ENV:-}" = "production" ] && [ "$DEBUG_ESCAPE" = "true" ]; then
    echo "⚠️  AMIAL_ALLOW_DEBUG مضبوطةٌ في بيئةِ إنتاج — **وتُتجاهَل**."
    echo "   منفذُ التنقيح لبيئات التطوير وحدَها."
    DEBUG_ESCAPE="false"
fi

if [ "$EFFECTIVE_DEBUG" = "true" ] && [ "$DEBUG_ESCAPE" != "true" ]; then
    echo "╔══════════════════════════════════════════════════════════╗"
    echo "║  ⛔ APP_DEBUG=true — والخدمةُ لن تبدأ                     ║"
    echo "╚══════════════════════════════════════════════════════════╝"
    echo "   ما ينكشف: آثارُ الاستثناءات كاملةً لأيّ مستخدمٍ يستقبل ٥٠٠ —"
    echo "   مساراتُ الملفّات، ونصوصُ الاستعلامات، وأسماءُ الأعمدة، وأحياناً"
    echo "   قيمُ المتغيّرات. وهي بياناتُ منصّةٍ ماليّة."
    echo ""
    echo "   الإصلاح: اضبط APP_DEBUG=false في متغيّرات البيئة ثمّ أعِد النشر."
    echo "   وإن كانت هذه بيئةَ تطويرٍ عن قصد: AMIAL_ALLOW_DEBUG=true"
    echo "   (ولا تعمل هذه في APP_ENV=production — المنفذُ مغلقٌ هناك.)"
    exit 1
fi

# **وبيئةٌ تقول «إنتاج» ثمّ تُخالف تُقال أيضاً.** (والتعليقُ في
# `entrypoint.prod.sh` كان يَعِد بهذا الفحص ولا يؤدّيه — وعدُ حراسةٍ
# لا وجودَ له أسوأ من غيابه.)
if [ -n "${APP_ENV:-}" ] && [ "${APP_ENV}" != "production" ]; then
    echo "⚠️  APP_ENV=${APP_ENV} — وليست production. راجِع إن كان هذا مقصوداً."
fi

# ── AMIAL-PROD-READINESS-001: ختمُ لحظة الإقلاع ──────────────────────
#
# يقرؤه `HealthCheckController::deployProbe` ليفرّق بين **«لم يجهز بعد»**
# و**«لن يجهز»**: الهجرةُ تجري في الخلفيّة أدناه، فمسبارٌ صارمٌ من الثانية
# الأولى يُسقط نشرةً سليمة. وبلا هذا الملفّ يصير المسبارُ صارماً — أي أنّ
# **الافتراضَ عند غياب المعلومة هو الصرامةُ لا التساهل.**
date -u +%s > /tmp/amial-boot-epoch 2>/dev/null || true
chmod 644 /tmp/amial-boot-epoch 2>/dev/null || true

# ── بدء الخادم فوراً (nginx على $PORT) ليمرّ الفحص الصحّي ─────────
echo "✅ يبدأ Supervisor فوراً على المنفذ ${PORT} (تهيئة القاعدة تجري في الخلفية)..."
exec /usr/bin/supervisord -n -c /etc/supervisord.conf
