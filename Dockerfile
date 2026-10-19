FROM php:8.4-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY src /app/src
COPY public /app/public
COPY docker/pool.conf /usr/local/etc/php-fpm.d/zz-demo.conf
