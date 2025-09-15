# Immagine base: PHP 8.2
FROM php:8.2-cli

# Cartella di lavoro dentro al container
WORKDIR /app

# Copiamo tutti i file del progetto dentro /app
COPY . /app

# Espone la porta (informativo, Railway usa $PORT automaticamente)
EXPOSE 8080

# Avvia il server PHP builtin con document root su /public
CMD [ "sh", "-c", "php -S 0.0.0.0:$PORT -t /app/public" ]
