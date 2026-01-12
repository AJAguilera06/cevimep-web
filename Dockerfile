FROM php:8.2-apache

# Ensure ONLY prefork MPM is enabled (required for mod_php)
RUN a2dismod mpm_event mpm_worker || true \
 && a2enmod mpm_prefork

# Enable rewrite
RUN a2enmod rewrite

# PHP extensions for MySQL
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Copy app
COPY . /var/www/html/

# Set DocumentRoot to /public
RUN sed -i 's#DocumentRoot /var/www/html#DocumentRoot /var/www/html/public#g' /etc/apache2/sites-available/000-default.conf \
 && sed -i 's#/var/www/#/var/www/html/#g' /etc/apache2/apache2.conf \
 && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Railway dynamic port
EXPOSE 8080
CMD ["bash", "-lc", "sed -i \"s/Listen 80/Listen ${PORT:-8080}/g\" /etc/apache2/ports.conf && apache2-foreground"]