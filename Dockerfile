# Alpine stabile + PHP 8.3 dai repo (ha pdo_mysql pronto)
FROM alpine:3.20

# Aggiorna indici e installa PHP + estensioni necessarie (pdo_mysql!)
RUN apk add --no-cache \
  php83 php83-cli php83-opcache php83-session \
  php83-pdo_mysql php83-mysqli \
  php83-mbstring php83-xml php83-json php83-curl php83-openssl ca-certificates && \
  ln -s /usr/bin/php83 /usr/bin/php

WORKDIR /app
COPY . /app

EXPOSE 8080

# Docroot /public (coerente con i tuoi path)
CMD ["sh", "-c", "php -S 0.0.0.0:$PORT -t /app/public"]
