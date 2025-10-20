# Base minimale e stabile: niente Docker Hub
FROM alpine:3.20

# PHP 8.3 + estensioni + tool necessari
RUN apk add --no-cache \
    php83 php83-cli php83-common php83-opcache \
    php83-mbstring php83-xml php83-json php83-curl php83-openssl \
    php83-pdo php83-pdo_mysql \
    ca-certificates git unzip curl composer \
 && ln -sf /usr/bin/php83 /usr/bin/php \
 && update-ca-certificates

# (facoltativo) verifica versione php e moduli
# RUN php -v && php -m

# App
WORKDIR /app
COPY . /app

# Se manca composer.json lo crea, poi installa AWS SDK
RUN php -r "if(!file_exists('composer.json')) file_put_contents('composer.json', json_encode(['name'=>'arena/app','require'=>new stdClass()], JSON_PRETTY_PRINT));" \
 && composer require aws/aws-sdk-php:^3 --no-interaction --no-ansi --no-progress

# Porta (Railway usa $PORT). Default 8080 per locale.
EXPOSE 8080
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} -t /app/public"]
