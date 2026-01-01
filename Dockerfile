FROM php:8.2-apache

RUN a2enmod rewrite

COPY . /var/www/html

RUN sed -i 's#/var/www/html#/var/www/html/public#g' /etc/apache2/sites-available/000-default.conf \
 && sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
 && sed -i 's/:80/:8080/' /etc/apache2/sites-available/000-default.conf \
 && chown -R www-data:www-data /var/www/html \
 && chmod -R 755 /var/www/html

EXPOSE 8080
ENV PORT=8080
