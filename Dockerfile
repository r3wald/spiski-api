# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# base – gemeinsame Laufzeit für dev, test und prod
# ---------------------------------------------------------------------------
FROM php:8.5-fpm-bookworm AS base

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql intl opcache zip apcu

# postgresql-client wird von bin/backup.sh und den Test-Fixtures gebraucht.
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip postgresql-client \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

WORKDIR /app

# ---------------------------------------------------------------------------
# dev – Code kommt per Bind-Mount, kein COPY
# ---------------------------------------------------------------------------
FROM base AS dev

# APP_ENV wird hier bewusst nicht gesetzt: als echte Umgebungsvariable landet es
# in $_ENV, und KernelTestCase liest $_ENV vor $_SERVER – der Testlauf wuerde
# dadurch im dev-Environment booten. Den Default liefert .env.
COPY docker/php/php.dev.ini /usr/local/etc/php/conf.d/99-spiski.ini

# ---------------------------------------------------------------------------
# prod – Code und vendor/ liegen im Image
# ---------------------------------------------------------------------------
FROM base AS prod

ENV APP_ENV=prod

COPY docker/php/php.prod.ini /usr/local/etc/php/conf.d/99-spiski.ini

# Abhängigkeiten zuerst, damit der Layer bei reinen Code-Änderungen im Cache bleibt.
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .

RUN composer dump-autoload --classmap-authoritative --no-dev \
 && composer run-script post-install-cmd --no-dev \
 && mkdir -p var/cache var/log \
 && chown -R www-data:www-data var

USER www-data
