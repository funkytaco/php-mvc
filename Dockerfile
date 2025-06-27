FROM composer:latest AS composer
FROM php:8.2-apache

USER root

RUN apt-get update && apt-get install -y \
    libzip-dev \
    libpq-dev \
    zip \
    unzip

RUN docker-php-ext-install \
    pdo_mysql \
    pdo_pgsql \
    zip

    COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

COPY . /var/www/

RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf
RUN sed -i 's/Listen 80/Listen 8080/g' /etc/apache2/ports.conf
RUN sed -i 's/:80/:8080/g' /etc/apache2/sites-enabled/*

# Set DocumentRoot to public directory
RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/public|g' /etc/apache2/sites-available/000-default.conf
RUN sed -i 's|<Directory /var/www/html>|<Directory /var/www/public>|g' /etc/apache2/apache2.conf

# Configure Apache to allow .htaccess overrides
RUN echo '<Directory /var/www/public>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/override.conf \
    && a2enconf override

# Create .htaccess file for FastRoute
RUN echo '<IfModule mod_rewrite.c>\n\
    RewriteEngine On\n\
    RewriteCond %{REQUEST_FILENAME} !-f\n\
    RewriteCond %{REQUEST_FILENAME} !-d\n\
    RewriteRule ^(.*)$ index.php [QSA,L]\n\
</IfModule>' > /var/www/public/.htaccess

RUN chown -R www-data:www-data /var/www \
    && a2enmod rewrite

RUN echo "extension_dir=/usr/local/lib/php/extensions/no-debug-non-zts-20220829" >> /usr/local/etc/php/php.ini \
    && echo "extension=pdo_mysql.so" >> /usr/local/etc/php/php.ini \
    && echo "extension=pdo_pgsql.so" >> /usr/local/etc/php/php.ini

USER www-data
WORKDIR /var/www/
RUN rm -rf app && composer install-lkui
EXPOSE 8080
CMD ["/bin/sh", "-c", "apache2-foreground"]
