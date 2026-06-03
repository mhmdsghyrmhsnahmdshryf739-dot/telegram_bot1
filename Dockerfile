FROM php:8.2-cli
RUN apt-get update && apt-get install -y git zip unzip
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY . .
RUN composer install
CMD php -S 0.0.0.0:$PORT -t .