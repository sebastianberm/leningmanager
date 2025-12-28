FROM php:8.2-apache
RUN apt-get update && apt-get install -y libcurl4-openssl-dev libsqlite3-dev git unzip && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install pdo pdo_sqlite
RUN docker-php-ext-install curl || true
COPY composer.json composer.lock /var/www/
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
 && composer install --no-dev --optimize-autoloader --working-dir=/var/www
COPY public/ /var/www/html/
COPY includes/ /var/www/includes/
COPY data/ /var/www/data/
RUN chown -R www-data:www-data /var/www/data
