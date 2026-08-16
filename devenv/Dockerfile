# The stock wordpress image has no Redis extension, so an object-cache plugin
# silently falls back to the database — which would quietly undo half of what
# this harness is meant to simulate. Object Cache Pro on Margaret's host needs
# phpredis (or Relay) too, so installing it here matches production rather than
# working around it.
FROM wordpress:php8.2-apache

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ${PHPIZE_DEPS}; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    apt-get purge -y --auto-remove ${PHPIZE_DEPS}; \
    rm -rf /var/lib/apt/lists/*
