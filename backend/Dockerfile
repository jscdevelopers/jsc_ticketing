# Development runtime. Review/update this digest before a staging release.
FROM php:8.2-cli-alpine@sha256:7a4a2928b2e7e1542cfb343b4846b57c68a0d05e58f7e72822317323a630d9e5
RUN apk add --no-cache libpq \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && apk del .build-deps \
    && addgroup -g 10001 ticketing \
    && adduser -D -u 10001 -G ticketing ticketing
WORKDIR /app
COPY --chown=ticketing:ticketing . /app
USER ticketing
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
