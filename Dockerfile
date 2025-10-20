# Base minimale e affidabile: NIENTE Docker Hub
FROM alpine:3.20

# Aggiorna indici e installa PHP 8.3 + estensioni + tool
RUN apk add --no-cache \
    # PHP 8.3 core + moduli
    php83 php83-cli php83-common php83-opcache \
    php83-mbstring php83-xml php83-json php83-curl php83-openssl \
    php83-pdo php83-pdo_mysql \
    # Tool utili
    ca-certificates git unzip curl composer

# Verifica (facoltativo): stampa versione PHP
RUN php -v && php -m

# App
WORKDIR /app
COPY . /app

# Se manca composer.json, creane uno minimale e installa AWS SDK
RUN php -r "if(!file_exists('composer.json')) file_put_contents('composer.json', json_encode(['name'=>'arena/app','require'=>new stdClass()], JSON_PRETTY_PRINT));" \
 && composer require aws/aws-sdk-php:^3 --no-interaction --no-ansi --no-progress

# Railway espone $PORT. Usiamo il server built-in di PHP.
EXPOSE 8080
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} -t /app/public"]
