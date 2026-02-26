FROM php:8.3-cli-alpine

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apk add --no-cache ffmpeg curl ca-certificates $PHPIZE_DEPS oniguruma-dev \
    && docker-php-ext-install mbstring \
    && apk del --no-network $PHPIZE_DEPS oniguruma-dev

WORKDIR /app
COPY composer.json composer.lock /app/
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist
COPY src/ /app/src/

CMD ["php", "/app/src/worker.php"]
