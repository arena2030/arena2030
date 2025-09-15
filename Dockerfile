# Dockerfile (root del repo)
FROM php:8.2-cli
WORKDIR /app
COPY . /app
EXPOSE 8080
CMD [ "sh", "-c", "php -S 0.0.0.0:$PORT -t /app/public" ]
