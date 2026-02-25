FROM php:8.3-cli-alpine

RUN apk add --no-cache ffmpeg curl ca-certificates

WORKDIR /app
COPY src/ /app/src/

CMD ["php", "/app/src/worker.php"]
