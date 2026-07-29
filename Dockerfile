# ---- Builder: gets the full build context and runs the app install.
# Nothing from this stage ships unless explicitly COPY'd into the final
# stage below — .installer/ (vault, per-app secrets, templates) never
# enters a final-image layer.
FROM php:8.3-apache AS builder

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

# Create .htaccess file for FastRoute
RUN echo '<IfModule mod_rewrite.c>\n\
    RewriteEngine On\n\
    RewriteCond %{REQUEST_FILENAME} !-f\n\
    RewriteCond %{REQUEST_FILENAME} !-d\n\
    RewriteRule ^(.*)$ index.php [QSA,L]\n\
</IfModule>' > /var/www/html/.htaccess
COPY public/index.php /var/www/html/index.php

RUN chown -R www-data:www-data /var/www

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

# ---- Final: runtime image. Copies only what Apache/PHP serve — app/,
# src/, vendor/, html/ and composer.json (dev mode's entrypoint runs
# `composer dump-autoload`). No build context, no .installer/.
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

RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf
RUN sed -i 's/Listen 80/Listen 8080/g' /etc/apache2/ports.conf
RUN sed -i 's/:80/:8080/g' /etc/apache2/sites-enabled/*
RUN a2enmod rewrite && chown www-data:www-data /var/www

COPY --from=builder --chown=www-data:www-data /var/www/composer.json /var/www/composer.json
COPY --from=builder --chown=www-data:www-data /var/www/app /var/www/app
COPY --from=builder --chown=www-data:www-data /var/www/src /var/www/src
COPY --from=builder --chown=www-data:www-data /var/www/vendor /var/www/vendor
COPY --from=builder --chown=www-data:www-data /var/www/html /var/www/html

USER www-data
WORKDIR /var/www/
EXPOSE 8080
CMD ["/bin/sh", "-c", "apache2-foreground"]
