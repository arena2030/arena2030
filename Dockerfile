FROM alpine:3.20

RUN apk add --no-cache \
    php83 php83-cli php83-common php83-opcache \
    php83-mbstring php83-xml php83-json php83-curl php83-openssl \
    php83-pdo php83-pdo_mysql php83-session \  # 👈 AGGIUNTO
    ca-certificates git unzip curl composer \
 && ln -sf /usr/bin/php83 /usr/bin/php \
 && update-ca-certificates

WORKDIR /app
COPY . /app

RUN php -r "if(!file_exists('composer.json')) file_put_contents('composer.json', json_encode(['name'=>'arena/app','require'=>new stdClass()], JSON_PRETTY_PRINT));" \
 && composer require aws/aws-sdk-php:^3 --no-interaction --no-ansi --no-progress

EXPOSE 8080
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} -t /app/public"]
