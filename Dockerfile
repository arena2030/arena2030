# Bypass Docker Hub: base Alpine + PHP 8.2 dai repo
FROM alpine:3.19

# Installa PHP 8.2 + estensioni necessarie (pdo_mysql!)
RUN apk add --no-cache \
  php82 php82-cli php82-opcache php82-session \
  php82-pdo_mysql php82-mysqli \
  php82-mbstring php82-xml php82-json php82-curl php82-openssl

# alias "php" -> "php82" (alcuni pacchetti lo espongono come php82)
RUN ln -s /usr/bin/php82 /usr/bin/php

WORKDIR /app
COPY . /app

EXPOSE 8080

# Avvia il server PHP builtin sulla docroot /public
CMD ["sh", "-c", "php -S 0.0.0.0:$PORT -t /app/public"]
