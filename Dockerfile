FROM php:8.3-cli-alpine

RUN apk add --no-cache ffmpeg curl ca-certificates composer

WORKDIR /app
COPY composer.json /app/composer.json
RUN composer install --no-dev --no-interaction --no-progress
COPY src/ /app/src/

CMD ["php", "/app/src/worker.php"]
