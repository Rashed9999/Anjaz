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
RUN printf "[www]\nlisten = 127.0.0.1:9001\ncatch_workers_output = yes\ndecorate_workers_output = no\nclear_env = no\n" > /usr/local/etc/php-fpm.d/zz-amial-port.conf

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
COPY . .

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

# تثبيت الحزم (بلا dev packages لبيئة الإنتاج/الديمو)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# تهيئة الإقلاع قابلة للتنفيذ
RUN chmod +x docker/entrypoint.sh

EXPOSE 80
# entrypoint يُهيّئ (منفذ Railway الديناميكي $PORT + migrations + cache) ثم يُشغّل supervisord
ENTRYPOINT ["/bin/sh", "/var/www/html/docker/entrypoint.sh"]
