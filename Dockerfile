FROM composer:latest AS composer
FROM php:8.2-apache

USER root

RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip

RUN docker-php-ext-install \
    pdo_mysql \
    zip

    COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

COPY . /var/www/

RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf
RUN sed -i 's/Listen 80/Listen 8080/g' /etc/apache2/ports.conf
RUN sed -i 's/:80/:8080/g' /etc/apache2/sites-enabled/*
RUN chown -R www-data:www-data /var/www/html \
    && a2enmod rewrite

USER www-data
WORKDIR /var/www/
RUN composer install-mvc
EXPOSE 8080
CMD ["/bin/sh", "-c", "apache2-foreground"]
