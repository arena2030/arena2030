# Usa immagine PHP ufficiale
FROM php:8.2-cli

# Imposta cartella di lavoro
WORKDIR /app

# Copia tutto il progetto dentro /app
COPY . /app

# Espone la porta (solo informativo, Railway usa $PORT)
EXPOSE 8080

# Avvia il server PHP builtin
# ATTENZIONE: serviamo /app (non solo /app/public)
# perché i tuoi link usano percorsi tipo /public/index.php
CMD [ "sh", "-c", "php -S 0.0.0.0:$PORT -t /app/public" ]
