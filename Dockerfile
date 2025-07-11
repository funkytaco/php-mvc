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

COPY . /var/www/

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
COPY public/index.php /var/www/html/index.php

RUN chown -R www-data:www-data /var/www \
    && a2enmod rewrite

USER www-data
WORKDIR /var/www/
ARG APP_NAME=lkui
RUN rm -rf app && rm -rf html/assets && \
    if [ "$APP_NAME" != "lkui" ]; then \
        echo "Installing app: $APP_NAME"; \
        composer nimbus:install $APP_NAME --no-dev --optimize-autoloader && \
        composer dump-autoload --optimize; \
    else \
        echo "Installing default LKUI app"; \
        composer install-lkui --no-dev --optimize-autoloader; \
    fi
EXPOSE 8080
CMD ["/bin/sh", "-c", "apache2-foreground"]
