# PHP 8.3 su Alpine
FROM php:8.3-cli-alpine

# Estensioni necessarie per pdo_mysql e per Composer
RUN apk add --no-cache $PHPIZE_DEPS linux-headers \
    php83-mbstring php83-xml php83-json php83-curl php83-openssl ca-certificates \
    git unzip curl composer

# Compila estensioni native richieste
RUN docker-php-ext-install pdo pdo_mysql

WORKDIR /app
COPY . /app

# Se non c'è composer.json lo creo minimale, poi installo l'SDK AWS
RUN php -r "if(!file_exists('composer.json')) file_put_contents('composer.json', json_encode(['name'=>'arena/app','require'=>new stdClass()], JSON_PRETTY_PRINT));" \
 && composer require aws/aws-sdk-php:^3 --no-interaction --no-ansi --no-progress

EXPOSE 8080
CMD ["sh", "-c", "php -S 0.0.0.0:$PORT -t /app/public"]
