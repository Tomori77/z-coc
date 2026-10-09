FROM php:8.3-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod headers rewrite deflate expires \
    && a2dissite 000-default

COPY docker/apache-z-coc.conf /etc/apache2/sites-available/z-coc.conf
COPY docker/php-production.ini /usr/local/etc/php/conf.d/z-coc-production.ini

RUN a2ensite z-coc

WORKDIR /var/www/html
COPY . /var/www/html/

RUN rm -rf /var/www/html/docker /var/www/html/Dockerfile /var/www/html/.dockerignore \
    && chown -R www-data:www-data /var/www/html

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r "exit(@file_get_contents('http://127.0.0.1:8080/health.php') === false ? 1 : 0);"

CMD ["apache2-foreground"]
