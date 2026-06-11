# Builds the customize-mode Joomla fork and serves it with Apache.
# The built tree lives at /usr/src/joomla; the (mounted) entrypoint copies it into the
# /var/www/html volume on first run, installs Joomla, and seeds demo content.
FROM php:8.3-apache

# PHP extensions Joomla needs, plus build tooling.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        git unzip libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev libicu-dev libonig-dev \
        default-mysql-client ca-certificates curl gnupg; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" gd mysqli pdo_mysql zip intl; \
    a2enmod rewrite; \
    rm -rf /var/lib/apt/lists/*

# Composer (Joomla's PHP vendor deps) and Node 22 (the media asset build).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN set -eux; \
    curl -fsSL https://deb.nodesource.com/setup_22.x | bash -; \
    apt-get install -y --no-install-recommends nodejs; \
    rm -rf /var/lib/apt/lists/*

# Clone the fork and build it: composer for PHP vendors, then the Joomla asset pipeline
# (npm ci runs build.mjs --prepare for vendor assets; npm run update copies + compiles
# build/media_source, which is where the customize engine and plugins live).
ARG REPO=https://github.com/hikashop-nicolas/joomla-cms.git
ARG BRANCH=feature/customize-mode
RUN git clone --depth 1 --branch "$BRANCH" "$REPO" /usr/src/joomla
WORKDIR /usr/src/joomla
RUN set -eux; \
    composer install --no-dev --no-interaction --optimize-autoloader --ignore-platform-req=ext-ldap; \
    npm ci; \
    npm run update; \
    npm cache clean --force; \
    rm -rf node_modules

EXPOSE 80
CMD ["apache2-foreground"]
