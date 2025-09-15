# PHP ufficiale, base Alpine
FROM php:8.3-cli-alpine

# Strumenti necessari per compilare estensioni
RUN apk add --no-cache $PHPIZE_DEPS linux-headers

# Estensioni richieste dal progetto (pdo_mysql)
RUN docker-php-ext-install pdo pdo_mysql

# Lavoro e codice
WORKDIR /app
COPY . /app

# Server integrato; docroot = /public
EXPOSE 8080
CMD ["sh", "-c", "php -S 0.0.0.0:$PORT -t /app/public"]
