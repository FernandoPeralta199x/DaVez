FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev \
    && docker-php-ext-install -j"$(nproc)" mysqli mbstring opcache \
    && a2enmod headers rewrite \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && printf 'ServerName localhost\n' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername \
    && mkdir -p /var/lib/davez-private/rate-limit \
    && chown -R www-data:www-data /var/lib/davez-private \
    && chmod 700 /var/lib/davez-private /var/lib/davez-private/rate-limit \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php/local.ini /usr/local/etc/php/conf.d/zz-davez-local.ini
WORKDIR /var/www/html
