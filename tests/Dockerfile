# The image scripts/test runs the PHP SDK's tests in: a pinned PHP image with
# pcntl, for the tests that signal and fork, zip, which Composer's artifact
# repositories need (scripts/check-package), unzip and git, which Composer
# uses to install packages, and Composer itself, pinned by version and SHA-256.
ARG PHP_IMAGE
FROM ${PHP_IMAGE}
ARG COMPOSER_VERSION=2.11.3
ARG COMPOSER_SHA256=04f8b003da4e3a2459056591fbfebd02b4dc3e5adeb158616b90cf55a65113ec
RUN apt-get update \
    && apt-get install --yes --no-install-recommends git unzip sqlite3 libzip-dev \
    && rm --recursive --force /var/lib/apt/lists/* \
    && docker-php-ext-install pcntl zip \
    && curl --fail --silent --show-error --location \
        --output /usr/local/bin/composer \
        "https://getcomposer.org/download/${COMPOSER_VERSION}/composer.phar" \
    && echo "${COMPOSER_SHA256}  /usr/local/bin/composer" | sha256sum --check --quiet \
    && chmod 755 /usr/local/bin/composer
