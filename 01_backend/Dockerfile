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
        zip \
        opcache \
        intl

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
RUN printf "[www]\nlisten = 127.0.0.1:9001\ncatch_workers_output = yes\ndecorate_workers_output = no\n" > /usr/local/etc/php-fpm.d/zz-amial-port.conf

# ── Nginx config ────────────────────────────────────────
COPY docker/nginx.conf /etc/nginx/nginx.conf

# ── Supervisor config ───────────────────────────────────
COPY docker/supervisord.conf /etc/supervisord.conf

# ── App ─────────────────────────────────────────────────
WORKDIR /var/www/html
COPY . .

# صلاحيات storage
RUN mkdir -p storage/logs storage/framework/{cache/data,sessions,views} \
             storage/app/public bootstrap/cache \
 && chmod -R 775 storage bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

# تثبيت الحزم (بلا dev packages لبيئة الإنتاج/الديمو)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# تهيئة الإقلاع قابلة للتنفيذ
RUN chmod +x docker/entrypoint.sh

EXPOSE 80
# entrypoint يُهيّئ (منفذ Railway الديناميكي $PORT + migrations + cache) ثم يُشغّل supervisord
ENTRYPOINT ["/bin/sh", "/var/www/html/docker/entrypoint.sh"]
