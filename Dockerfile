# Airlock without PHP on the machine: PHP 8.5, bubblewrap and git, with Airlock and its dependencies.
# Commands then run inside this container, with its tools, on the project mounted at the same path.
FROM php:8.5-cli

RUN apt-get update \
 && apt-get install -y --no-install-recommends bubblewrap git unzip \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

ENV APP_ENV=prod

# The version Airlock reports: Composer reads it from git, which the image leaves out (a tag: --build-arg).
ARG COMPOSER_ROOT_VERSION=dev-main

WORKDIR /app
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress

COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
 && php bin/console cache:warmup \
 && chmod -R a+rwX var

ENTRYPOINT ["php", "/app/bin/console", "mcp:server"]
