FROM php:8.2-fpm-alpine

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

# ── PHP config ──────────────────────────────────────────
RUN echo "upload_max_filesize=50M"  >> /usr/local/etc/php/conf.d/amial.ini \
 && echo "post_max_size=50M"        >> /usr/local/etc/php/conf.d/amial.ini \
 && echo "memory_limit=256M"        >> /usr/local/etc/php/conf.d/amial.ini \
 && echo "max_execution_time=120"   >> /usr/local/etc/php/conf.d/amial.ini \
 && echo "opcache.enable=1"         >> /usr/local/etc/php/conf.d/amial.ini \
 && echo "opcache.memory_consumption=128" >> /usr/local/etc/php/conf.d/amial.ini

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

EXPOSE 80
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisord.conf"]
