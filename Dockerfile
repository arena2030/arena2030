# PHP 8.3 + Apache (solido per prod)
FROM php:8.3-apache

# — Dipendenze e estensioni
RUN apt-get update && apt-get install -y \
      git zip unzip libzip-dev curl \
  && docker-php-ext-install pdo pdo_mysql \
  && docker-php-ext-install opcache \
  && a2enmod rewrite headers

# — Composer ufficiale
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# — Codice applicativo
WORKDIR /var/www/html
COPY . /var/www/html

# — DocumentRoot su /public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's#DocumentRoot /var/www/html#DocumentRoot ${APACHE_DOCUMENT_ROOT}#g' /etc/apache2/sites-available/000-default.conf \
 && sed -ri 's#<Directory /var/www/>#<Directory ${APACHE_DOCUMENT_ROOT}/>#g' /etc/apache2/apache2.conf || true \
 && sed -ri 's#<Directory /var/www/html/>#<Directory ${APACHE_DOCUMENT_ROOT}/>#g' /etc/apache2/apache2.conf || true

# — Install deps PHP (se presenti)
RUN if [ -f composer.json ]; then composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader; fi
# — Se l’SDK AWS non è nel composer.json, lo aggiungo:
RUN if [ ! -f composer.json ] || ! grep -q '"aws/aws-sdk-php"' composer.json; then \
      composer require aws/aws-sdk-php:^3 --no-interaction --prefer-dist; \
    fi

# — Abilita override .htaccess
RUN printf '<Directory ${APACHE_DOCUMENT_ROOT}>\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-available/override.conf \
 && a2enconf override

# — PHP ini: produzione + opcache
RUN { \
      echo "display_errors=0"; \
      echo "log_errors=1"; \
      echo "error_reporting=E_ALL & ~E_DEPRECATED & ~E_STRICT"; \
      echo "opcache.enable=1"; \
      echo "opcache.enable_cli=0"; \
      echo "opcache.validate_timestamps=0"; \
      echo "opcache.max_accelerated_files=20000"; \
      echo "opcache.memory_consumption=192"; \
      echo "opcache.interned_strings_buffer=16"; \
    } > /usr/local/etc/php/conf.d/production.ini

# — Entrypoint: bind su $PORT di Railway
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# — Healthcheck semplice (richiede /public/health.php)
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD curl -fsS "http://127.0.0.1:${PORT:-8080}/health.php" || exit 1

CMD ["/usr/local/bin/docker-entrypoint.sh"]
