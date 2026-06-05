FROM php:8.5-fpm

# install-php-extensions zvládá všechny verze PHP automaticky
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN apt-get update && apt-get install -y \
    git curl unzip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions pdo_pgsql zip intl opcache redis

# PHP config pro velké uploady
RUN echo "upload_max_filesize = 0"    >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 0"       >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 512M"     >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time = 3600" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_input_time = 3600"   >> /usr/local/etc/php/conf.d/uploads.ini

# OPcache — výrazně zrychluje PHP v produkci
# validate_timestamps=0 → nekontrolovat mtime souborů (nutné restartovat kontejner po deploy)
RUN echo "opcache.enable=1"                  >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.memory_consumption=256" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.interned_strings_buffer=16" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.max_accelerated_files=20000" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.validate_timestamps=0"  >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.save_comments=1"        >> /usr/local/etc/php/conf.d/opcache.ini

# PHP-FPM pool: více workers pro produkční zátěž
RUN echo "[www]"                              >> /usr/local/etc/php-fpm.d/zz-prod.conf \
    && echo "pm = dynamic"                   >> /usr/local/etc/php-fpm.d/zz-prod.conf \
    && echo "pm.max_children = 20"           >> /usr/local/etc/php-fpm.d/zz-prod.conf \
    && echo "pm.start_servers = 5"           >> /usr/local/etc/php-fpm.d/zz-prod.conf \
    && echo "pm.min_spare_servers = 3"       >> /usr/local/etc/php-fpm.d/zz-prod.conf \
    && echo "pm.max_spare_servers = 10"      >> /usr/local/etc/php-fpm.d/zz-prod.conf \
    && echo "pm.max_requests = 500"          >> /usr/local/etc/php-fpm.d/zz-prod.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
