FROM php:8.4-fpm-alpine

# ── System deps ─────────────────────────────────────────
RUN apk add --no-cache \
    nginx \
    supervisor \
    mysql-client \
    git \
    unzip \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    libzip-dev \
    freetype-dev \
    oniguruma-dev \
    icu-dev \
    # AMIAL-KYC-OCR-001: يُثبَّت هنا أيضاً ليطابق الإنتاج — بيئةُ تطويرٍ
    # تختلف عن الإنتاج تُنتج «يعمل عندي» ولا يعمل هناك.
    tesseract-ocr \
    tesseract-ocr-data-ara \
    tesseract-ocr-data-eng \
    # ══════════════════════════════════════════════════════════════════
    # AMIAL-BACKUP-OFFSITE-001 — **أداةُ الشحن تُشحَن مع الصورة.**
    #
    # `DatabaseBackupCommand::shipOffsite` تنسخ بـ`rclone` متى ضُبطت
    # `AMIAL_BACKUP_REMOTE`. **ولم تكن `rclone` في الصورة إطلاقاً** —
    # فأوّلُ ما يضبط صاحبُ المشروعُ الوجهةَ يسقط الشحنُ، والشيفرةُ
    # تقولها بصدق («الوجهةُ مضبوطةٌ ولا rclone») لكنّ الخطوةَ لا تنجح.
    #
    # وهو نمطُ «مبنيٌّ ولا يُوصَل إليه» في أخطر موضع: **النسخةُ خارج
    # الخادم**. وقرصٌ واحدٌ يُفقِد الأصلَ والنسخةَ معاً.
    #
    # وحجمُها ~٢٠ ميجابايت في Alpine، وتُثبَّت مرّةً في البناء لا في
    # كلّ ليلة — **وتثبيتٌ وقتَ الحاجة يعني شبكةً تعمل ليلةَ الكارثة.**
    # ══════════════════════════════════════════════════════════════════
    rclone \
    nodejs \
    npm

# ── PHP Extensions ──────────────────────────────────────
RUN docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        opcache \
        intl \
    # AMIAL-BUILD-001: zip يُبنى وحده بلا توازٍ.
    #
    # `-j$(nproc)` يُمرَّر إلى make، وهدفا zip — php_zip.lo و zip_stream.lo —
    # يتسابقان على إنشاء المجلّد .libs، فيفشل أحدهما بـ:
    #     mkdir: can't create directory '.libs': File exists
    #
    # عطل متقطّع يعتمد على عدد الأنوية وتوقيتها: نجحت نشرات كثيرة بالصدفة ثم
    # فشلت واحدة بلا تغيير في الشيفرة. بناؤه وحده يُنهي السباق، وكلفته ثوانٍ
    # لأن بقيّة الامتدادات تبقى متوازية.
    && docker-php-ext-install zip

# ── Composer ────────────────────────────────────────────
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer
# السماح لـ composer بالعمل كـ root داخل بناء Docker (يمنع تعطيل الإضافات)
ENV COMPOSER_ALLOW_SUPERUSER=1

# ══════════════════════════════════════════════════════════════════════
# AMIAL-BUILD-003 — **حدُّ ذاكرة composer يُرفَع صراحةً.**
#
# `memory_limit=256M` يُكتب في `php.ini` أعلاه لأجل التطبيق. وcomposer
# يقرأ الملفَّ نفسَه، وحلُّ شجرةِ تبعيّاتٍ بمئة حزمة يتجاوزها. **وسقوطُه
# على الذاكرة لا يقول «نفدت الذاكرة»**: يُقتَل فيبدو البناءُ منقطعاً بلا
# سبب — وهو ما يصعب تشخيصُه من سجلٍّ بعيد.
#
# و`-1` هنا لا تمسّ التطبيق: متغيّرُ بيئةٍ يقرؤه composer وحدَه.
ENV COMPOSER_MEMORY_LIMIT=-1

# ── PHP config ──────────────────────────────────────────
RUN echo "upload_max_filesize=50M"  >> /usr/local/etc/php/conf.d/amial.ini \
 && echo "post_max_size=50M"        >> /usr/local/etc/php/conf.d/amial.ini \
 && echo "memory_limit=256M"        >> /usr/local/etc/php/conf.d/amial.ini \
 && echo "max_execution_time=120"   >> /usr/local/etc/php/conf.d/amial.ini \
 && echo "opcache.enable=1"         >> /usr/local/etc/php/conf.d/amial.ini \
 && echo "opcache.memory_consumption=128" >> /usr/local/etc/php/conf.d/amial.ini

# ── php-fpm على المنفذ 9001 (يترك 9000 لخادم الويب على Railway) ──
# مهم: منفذ Railway الديناميكي قد يكون 9000؛ لو ظلّ php-fpm عليه يتعارض
# مع nginx فيفشل الوصول العام (502). ننقله إلى 9001.
# clear_env=no: العمّال يرثون بيئة الحاوية (متغيّرات Railway: DB_*، APP_KEY المُصدَّر…).
# افتراضياً clear_env=yes يُفرّغ بيئة العامل فلا يرى env('APP_KEY') → 500 على الدخول.
# (نكتب أيضاً APP_KEY في .env كضمان مزدوج — انظر entrypoint.sh).
# ══════════════════════════════════════════════════════════════════════
# AMIAL-CAPACITY-001 — **سقفُ التزامن كان خمسةً، ولم يكن مضبوطاً.**
#
# صورةُ `php:8.4-fpm-alpine` تشحن `www.conf` بـ`pm.max_children = 5`،
# **ولم يُغيَّر في هذا الملفّ قطّ**. فالمنصّةُ كلُّها — لوحةُ الإدارة
# وبوّابةُ الوكيل وواجهةُ التطبيق — تخدم **خمسةَ طلباتٍ متزامنة**، وما
# زاد ينتظر في طابور nginx حتّى تنتهي مهلتُه.
#
# **ولا يظهر هذا في أيّ اختبار:** الاختباراتُ تجري في عمليّةٍ واحدة،
# واختبارُ الضغط الماليّ يستدعي الخدماتِ مباشرةً ولا يمرّ بـFPM أصلاً
# (وقد قاس ٣٠٦ عمليّة/ث — فالمحرّكُ ليس العنق). والعنقُ هنا وحدَه.
#
# **والأرقامُ محسوبةٌ لا مخترَعة:**
#
#   memory_limit = 256M   ·   ذاكرةُ الطلب الوسطى في Laravel ≈ 40–60M
#   حاويةُ 2 جيجا  ⇒  2048 / 60 ≈ 34 عاملاً بهامشٍ للنظام وMySQL
#   وسقفُ اتّصالات MySQL 151 ⇒ 24 عاملاً + عاملَي طابور آمنان
#
# فـ`pm.max_children = 24` تُعطي ~٢٤ طلباً متزامناً. وبزمن استجابةٍ
# مقيسٍ ٢٠–٤٠ms للنداء الواحد ⇒ **~٦٠٠–١٢٠٠ طلبٍ في الثانية نظريّاً**،
# وهو أكثرُ من كافٍ لألفي مستخدمٍ (ذروتُهم المعتادة ٢–٥٪ متزامنين).
#
# **و`pm.max_requests` تمنع تسرّبَ الذاكرة** من إسقاط العامل بعد ساعات.
# ══════════════════════════════════════════════════════════════════════
RUN printf "[www]\nlisten = 127.0.0.1:9001\ncatch_workers_output = yes\ndecorate_workers_output = no\nclear_env = no\npm = dynamic\npm.max_children = 24\npm.start_servers = 6\npm.min_spare_servers = 4\npm.max_spare_servers = 12\npm.max_requests = 500\n" > /usr/local/etc/php-fpm.d/zz-amial-port.conf

# ── Nginx config ────────────────────────────────────────
COPY docker/nginx.conf /etc/nginx/nginx.conf

# ── Supervisor config ───────────────────────────────────
COPY docker/supervisord.conf /etc/supervisord.conf

# ── App ─────────────────────────────────────────────────
# AMIAL-CLOCK-GUARD-001 — ختمُ زمن البناء.
#
# **ولماذا؟** لأنّ ساعةَ خادمٍ خاطئةً لا تشتكي: تعمل، وتُصدر أرقاماً، وكلُّ
# ما يُبنى عليها كاذب. ووقعت مرّتين — ٣٥ ساعةً ثمّ ٤٤ — ولم تُكتشف إلّا
# حين اصطدم بها مستخدمٌ برسالة «انتهت صلاحية الصفحة».
#
# ولا شبكةَ في الإقلاع نضمنها لسؤال خادم زمن. لكنّ حقيقةً واحدةً تكفي:
# **لا يمكن أن يكون الآن أقدمَ من لحظة بناء هذه الصورة.** فإن كان، فالساعة
# خاطئةٌ يقيناً — بلا إنترنت وبلا مقارنةٍ بأحد.
RUN date -u +%s > /etc/amial-build-epoch

WORKDIR /var/www/html

# ══════════════════════════════════════════════════════════════════════
# AMIAL-BUILD-002 — **الحزمُ تُثبَّت قبل نسخ الشيفرة، لا بعدها.**
#
# **الثمنُ الذي قِيس من سجلّ Coolify نفسِه:**
#
# كان الترتيبُ `COPY . .` ثمّ `composer install`. وطبقةُ Docker تُبطَل
# بكلّ ما بعد أوّل تغيير، **فأيُّ تعديلٍ في سطرٍ واحدٍ من PHP يُعيد تثبيتَ
# مئةِ حزمةٍ من الصفر**. والسجلّ يقوله بالأرقام:
#
#     #8 … #16   CACHED          ← كلُّ ما قبل نسخ الشيفرة
#     #17 COPY . .   DONE 0.8s   ← تغيّرت الشيفرة فبطلت الطبقة
#     #20 composer install → 532 ثانيةً ثمّ سقط البناء
#
# **فتسعُ دقائقَ في كلّ نشرة**، ونافذةُ سقوطٍ تُفتَح في كلّ مرّة —
# لا لشيءٍ إلّا أنّ الترتيبَ يجعل الحزمَ تابعةً للشيفرة وهي ليست كذلك:
# **`composer.lock` وحدَه يقرّر ما يُثبَّت.**
#
# ══════════════════════════════════════════════════════════════════════
# **وأخطرُ ما فيه أنّ الصوابَ كان مكتوباً في الملفّ الآخر.**
#
# `Dockerfile.prod` يفعل هذا بالضبط منذ `AMIAL-PROD-READINESS-002`.
# **وCoolify يبني `Dockerfile` لا `.prod`** — وهو العطلُ المكتوب في
# `CLAUDE.md`: «الصورةُ المفحوصةُ ليست المشحونة»، واقعاً هذه المرّة على
# ترتيب الطبقات بدل حاجز `APP_DEBUG`.
#
# الحارس: `DockerLayerCacheGuardTest` — يمنع رجوعَ الترتيب.
COPY composer.json composer.lock ./

# `--no-scripts` و`--no-autoloader` شرطُ عملٍ لا تحسين: السكربتاتُ تُقلع
# Laravel، ولا شيفرةَ بعد — `artisan` نفسُه لم يُنسخ. وخريطةُ التحميل
# تُبنى بعد نسخ الشيفرة.
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY . .

# وهنا تُبنى الخريطةُ وتُطلق السكربتاتُ (`package:discover`) — والصورةُ
# هذه تملك كلَّ الامتدادات، بخلاف مرحلةِ `composer:2.7` في `Dockerfile.prod`.
#
# بعض مساحات Coolify القديمة كانت تُلحق `config/initial.php` من مُثبّت
# 6cash خارج Git رغم .dockerignore. Laravel يحمّل كل config/*.php، فيسقط
# النشر على خطأ نحوي في ملف ليس جزءاً من أميال باي. الحذف داخل الصورة هو
# الحارس الأخير: لا يمس المستودع أو بيانات التشغيل، لكنه يمنع ملفاً دخيلاً
# من الوصول إلى artisan.
RUN rm -f config/initial.php \
 && composer dump-autoload --no-dev --optimize --no-interaction

# صلاحيات storage
# AMIAL-PDF-DURABLE-001: تُنشأ هنا للنشرات بلا volume، ويُعاد إنشاؤها في
# entrypoint لأن volume على storage/app يحجب ما في الصورة تحته.
RUN mkdir -p storage/logs storage/framework/{cache/data,sessions,views} \
             storage/app/{public,mpdf,private,receipts,documents,signatures} bootstrap/cache \
 && chmod -R 775 storage bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

# ══════════════════════════════════════════════════════════════════════
# AMIAL-PDF-TRUNCATION-001 — مجلّدات nginx المؤقّتة مملوكةً لـwww-data.
#
# **العطل: «Connection closed while receiving data» على تنزيل PDF.**
#
# nginx يعمل بمستخدم `www-data`، وأيّ ردٍّ يتجاوز مخازن FastCGI يُكتب إلى
# `fastcgi_temp`. وهذه المجلّدات لم تكن تُنشأ ولا تُملَّك في هذه الصورة —
# فتفشل الكتابة **بعد إرسال الترويسات**، وتُقطع الاستجابة في منتصفها.
#
# ولا يظهر في سجلّ Laravel شيء: التطبيق أنهى عمله بنجاح، والقطع بعده.
RUN mkdir -p /var/lib/nginx/tmp/{fastcgi,proxy,client_body,scgi,uwsgi} \
             /var/cache/nginx \
 && chown -R www-data:www-data /var/lib/nginx /var/cache/nginx \
 && chmod -R 755 /var/lib/nginx /var/cache/nginx

# (‏تثبيتُ الحزم انتقل إلى أعلى — قبل نسخ الشيفرة. انظر AMIAL-BUILD-002.)

# تهيئة الإقلاع قابلة للتنفيذ
RUN chmod +x docker/entrypoint.sh

EXPOSE 80

# ══════════════════════════════════════════════════════════════════════
# AMIAL-HEALTH-DEPLOY-001 — **فحصُ الصحّة تُعلنه الصورةُ، لا لوحةُ Coolify.**
#
# **ما قِيس:** لوحةُ Coolify تقول `Running (no health check)` — فكلُّ
# نشرةٍ «ناجحة» مهما كانت حالةُ التطبيق. وضبطُه كان مكتوباً خطوةً يدويّةً
# في مرشد الإطلاق، **وخطوةٌ يدويّةٌ تُنسى**.
#
# و`HEALTHCHECK` **موجودٌ في `Dockerfile.prod`** — وهو الملفُّ الذي لا
# يبنيه Coolify. فهو ضبطٌ في صورةٍ لا تُشحَن: نمطُ «مبنيٌّ ولا يُوصَل
# إليه» نفسُه، واقعاً على الرصد.
#
# **ولا يُسأل `/railway-health`:** ذاك مسبارُ نشرٍ بنافذة سماحٍ للإقلاع
# (١٨٠ث) — يقول «نعم» أثناء الهجرات عمداً. **ومسبارٌ متسامحٌ لا يصلح
# حارساً دائماً.** فيُسأل `/health/readiness`: قاعدةٌ حيّةٌ ومهاجراتٌ
# مكتملةٌ وتخزينٌ يُكتَب فيه.
#
# **والمنفذُ يُجرَّب لا يُفترَض:** entrypoint يُنشئ إصغاءً على `$PORT`
# (ديناميكيّ) و9000 و8080. فيُجرَّب الثلاثةُ — ومنفذٌ مكتوبٌ رقماً واحداً
# يجعل الفحصَ يسقط دائماً، **وفحصٌ يسقط دائماً أسوأ من غيابه**: يُعيد
# تشغيلَ حاويةٍ سليمةٍ كلَّ دقيقة.
#
# `start-period` ١٨٠ث تُطابق نافذةَ سماح `deployProbe` — فلا تُقتَل
# نشرةٌ سليمةٌ هجرتُها تجري.
# ══════════════════════════════════════════════════════════════════════
HEALTHCHECK --interval=30s --timeout=10s --start-period=180s --retries=3 \
    CMD sh -c 'for p in "${PORT:-80}" 9000 8080 80; do \
        curl -fsS -m 8 "http://127.0.0.1:$p/health/readiness" >/dev/null 2>&1 && exit 0; \
      done; exit 1'

# entrypoint يُهيّئ (منفذ Railway الديناميكي $PORT + migrations + cache) ثم يُشغّل supervisord
ENTRYPOINT ["/bin/sh", "/var/www/html/docker/entrypoint.sh"]
