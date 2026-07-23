ARG PHP_VERSION=8.4.23
ARG COMPOSER_VERSION=2.10.2

FROM php:${PHP_VERSION}-fpm-bookworm AS base

ARG COMPOSER_VERSION
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    git \
    libfcgi-bin \
    libpq-dev \
    libzip-dev \
    unzip \
    && docker-php-ext-configure zip \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        opcache \
        pcntl \
        pdo_pgsql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && curl -sS "https://getcomposer.org/download/${COMPOSER_VERSION}/composer.phar" \
        -o /usr/local/bin/composer \
    && chmod +x /usr/local/bin/composer \
    && rm -rf /var/lib/apt/lists/*

COPY php.ini /usr/local/etc/php/conf.d/99-fake-link.ini
COPY www.conf /usr/local/etc/php-fpm.d/zz-fake-link.conf
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

WORKDIR /var/www/html

RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]

FROM base AS dev

RUN apt-get update && apt-get install -y --no-install-recommends \
    procps \
    && rm -rf /var/lib/apt/lists/*

COPY pcov.ini /usr/local/etc/php/conf.d/98-fake-link-pcov.ini

RUN pecl install pcov \
    && docker-php-ext-enable pcov

FROM base AS prod

FROM prod AS runtime
