# Dockerfile — PHP + Apache con Composer e AWS SDK per R2
FROM php:8.3-apache

# Estensioni utili
RUN apt-get update && apt-get install -y \
    git zip unzip libzip-dev \
 && docker-php-ext-install pdo pdo_mysql \
 && docker-php-ext-configure zip \
 && docker-php-ext-install zip \
 && a2enmod rewrite headers

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Se non esiste composer.json, creane uno minimale (evita errori in install)
RUN if [ ! -f composer.json ]; then \
      echo '{ "name": "arena/blu", "require": {} }' > composer.json ; \
    fi

# SDK AWS
RUN composer require aws/aws-sdk-php:^3 --no-interaction --prefer-dist

# Abilita Apache Rewrite e headers
RUN a2enmod rewrite headers && \
    echo '<Directory /var/www/html>\nAllowOverride All\n</Directory>' > /etc/apache2/conf-available/override.conf && \
    a2enconf override && \
    service apache2 restart

# Copia codice (in CI sostituisci con COPY . .)
# COPY . .
