# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# base – gemeinsame Laufzeit für dev, test und prod
# ---------------------------------------------------------------------------
# FrankenPHP: PHP und Webserver (Caddy) samt Mercure-Hub in einem Prozess.
# install-php-extensions bringt das Image mit.
FROM dunglas/frankenphp:1.13-php8.5-bookworm AS base

RUN install-php-extensions pdo_pgsql intl opcache zip apcu

# postgresql-client wird von bin/backup.sh und den Test-Fixtures gebraucht.
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip postgresql-client \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# vendor/bin hinten angehaengt: phpunit, phpstan & Co. ohne Pfad aufrufbar, ohne
# System-Binaries zu ueberdecken. In prod fehlen die Dev-Werkzeuge (--no-dev).
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer \
    PATH="${PATH}:/app/vendor/bin"

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

# Ohne root auf Port 80 lauschen; Caddy braucht seine Daten-Verzeichnisse.
RUN setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
 && chown -R www-data:www-data /data/caddy /config/caddy

USER www-data
