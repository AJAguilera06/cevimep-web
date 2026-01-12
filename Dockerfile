FROM php:8.2-apache

# Activar mod_rewrite
RUN a2enmod rewrite

# Extensiones MySQL
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Copiar proyecto
COPY . /var/www/html/

# Cambiar DocumentRoot a /public
RUN sed -i 's#DocumentRoot /var/www/html#DocumentRoot /var/www/html/public#g' /etc/apache2/sites-available/000-default.conf \
 && sed -i 's#/var/www/#/var/www/html/#g' /etc/apache2/apache2.conf \
 && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Puerto dinámico Railway
EXPOSE 8080
CMD ["bash", "-lc", "sed -i \"s/Listen 80/Listen ${PORT:-8080}/g\" /etc/apache2/ports.conf && apache2-foreground"]
