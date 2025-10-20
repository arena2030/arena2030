# PHP 8.3 su Alpine (mirror AWS ECR Public: stesso contenuto di Docker Hub, ma senza limiti o 503)
FROM public.ecr.aws/docker/library/php:8.3-cli-alpine

# Estensioni necessarie per pdo_mysql e per Composer
RUN apk add --no-cache $PHPIZE_DEPS linux-headers \
    php83-mbstring php83-xml php83-json php83-curl php83-openssl ca-certificates \
    git unzip curl composer

# Compila estensioni native richieste
RUN docker-php-ext-install pdo pdo_mysql

# Imposta la directory di lavoro dell'applicazione
WORKDIR /app

# Copia tutto il progetto all'interno del container
COPY . /app

# Se non esiste composer.json, ne crea uno minimale, poi installa AWS SDK PHP
RUN php -r "if(!file_exists('composer.json')) file_put_contents('composer.json', json_encode(['name'=>'arena/app','require'=>new stdClass()], JSON_PRETTY_PRINT));" \
 && composer require aws/aws-sdk-php:^3 --no-interaction --no-ansi --no-progress

# Espone la porta usata dal server PHP
EXPOSE 8080

# Avvia il server PHP interno su 0.0.0.0:$PORT (usato da Railway)
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} -t /app/public"]
