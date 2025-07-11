FROM composer:latest AS composer
FROM php:8.3-apache

USER root

RUN apt-get update && apt-get install -y \
    libzip-dev \
    libpq-dev \
    zip \
    unzip \
    && docker-php-ext-install zip pdo pdo_pgsql pdo_mysql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer
# COPY composer.json /var/www/composer.json
# COPY ApplicationTasks.php /var/www/ApplicationTasks.php
# COPY .installer /var/www/.installer
COPY . /var/www/
WORKDIR /var/www/


RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf
RUN sed -i 's/Listen 80/Listen 8080/g' /etc/apache2/ports.conf
RUN sed -i 's/:80/:8080/g' /etc/apache2/sites-enabled/*


# Create .htaccess file for FastRoute
RUN echo '<IfModule mod_rewrite.c>\n\
    RewriteEngine On\n\
    RewriteCond %{REQUEST_FILENAME} !-f\n\
    RewriteCond %{REQUEST_FILENAME} !-d\n\
    RewriteRule ^(.*)$ index.php [QSA,L]\n\
</IfModule>' > /var/www/html/.htaccess
RUN echo "<?php require __DIR__ .'/../src/Bootstrap.php';"  > /var/www/html/index.php

RUN chown -R www-data:www-data /var/www \
    && a2enmod rewrite

USER www-data
WORKDIR /var/www/
RUN rm -rf app && rm -rf html/assets && composer install-nagios --no-dev --optimize-autoloader
EXPOSE 8080
CMD ["/bin/sh", "-c", "apache2-foreground"]