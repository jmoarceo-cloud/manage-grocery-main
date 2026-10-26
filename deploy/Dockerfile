FROM php:8.2-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev \
      libzip-dev libicu-dev mariadb-client \
 && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql gd zip exif intl \
 && a2enmod rewrite remoteip headers \
 && rm -rf /var/lib/apt/lists/*

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY vhost.conf /etc/apache2/sites-available/000-default.conf

# Apache banner hardening
RUN { echo 'ServerTokens Prod'; echo 'ServerSignature Off'; } > /etc/apache2/conf-enabled/zz-hardening.conf
