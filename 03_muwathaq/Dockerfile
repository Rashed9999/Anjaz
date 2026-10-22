FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev curl \
    && docker-php-ext-install pdo_mysql curl mbstring \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY public/ /var/www/html/
COPY src/ /var/www/src/
COPY bin/ /var/www/bin/
COPY scripts/ /var/www/scripts/
COPY docker/000-default.conf /etc/apache2/sites-available/000-default.conf

# Stop the image build before deployment if any PHP file fails to parse
# with the actual PHP 8.3 runtime used by this container.
RUN find /var/www/html /var/www/src /var/www/bin -type f -name '*.php' -print0 \\
    | xargs -0 -r -n1 php -l

RUN chown -R www-data:www-data /var/www/html /var/www/src /var/www/bin /var/www/scripts

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl --fail --silent http://127.0.0.1/health || exit 1
