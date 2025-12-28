FROM php:8.2-apache
RUN apt-get update && apt-get install -y libcurl4-openssl-dev && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install pdo pdo_sqlite
RUN docker-php-ext-install curl || true
COPY public/ /var/www/html/
COPY includes/ /var/www/includes/
COPY assets/ /var/www/html/assets/
COPY data/ /var/www/data/
RUN chown -R www-data:www-data /var/www/data
