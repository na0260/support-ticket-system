FROM php:8.3-fpm-alpine

# Host UID/GID mapping so bind-mounted files stay editable from the host
ARG UID=1000
ARG GID=1000

# System dependencies (build deps kept in a virtual package for later removal)
RUN apk add --no-cache \
        git \
        curl \
        libpng-dev \
        libxml2-dev \
        zip \
        unzip \
        libzip-dev \
        oniguruma-dev \
        shadow \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        linux-headers

# PHP core extensions
RUN docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip

# PECL redis extension
RUN pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Remap www-data to the host UID/GID
RUN groupmod -o -g "${GID}" www-data \
    && usermod -o -u "${UID}" -g www-data www-data

# Upload limits aligned with nginx client_max_body_size
RUN { \
        echo 'upload_max_filesize=64M'; \
        echo 'post_max_size=64M'; \
        echo 'memory_limit=512M'; \
    } > /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html

RUN chown -R www-data:www-data /var/www/html

USER www-data

EXPOSE 9000

CMD ["php-fpm"]
