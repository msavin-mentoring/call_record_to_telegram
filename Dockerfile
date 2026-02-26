FROM php:8.3-cli-alpine

RUN apk add --no-cache ffmpeg curl ca-certificates composer

WORKDIR /app
COPY composer.json composer.lock /app/
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist
COPY src/ /app/src/

CMD ["php", "/app/src/worker.php"]
