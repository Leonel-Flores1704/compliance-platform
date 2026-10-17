FROM php:8.3-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
    curl git unzip procps libpq-dev \
    && rm -rf /var/lib/apt/lists/*

COPY --from=ghcr.io/mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql pgsql redis pcntl bcmath

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]