FROM php:8.5-fpm

# install-php-extensions zvládá všechny verze PHP automaticky
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN apt-get update && apt-get install -y \
    git curl unzip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions pdo_pgsql zip intl opcache redis

# PHP config pro velké uploady
RUN echo "upload_max_filesize = 0" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 0" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 512M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time = 3600" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_input_time = 3600" >> /usr/local/etc/php/conf.d/uploads.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
