FROM php:8.2-cli

WORKDIR /app

# Installa estensione PDO MySQL
RUN docker-php-ext-install pdo pdo_mysql

COPY . /app

EXPOSE 8080

CMD ["sh", "-c", "php -S 0.0.0.0:$PORT -t /app/public"]
