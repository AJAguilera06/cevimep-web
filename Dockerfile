FROM php:8.2-apache

# ---- MPM FIX DEFINITIVO ----
# Eliminar CUALQUIER MPM habilitado
RUN rm -f /etc/apache2/mods-enabled/mpm_event.load \
 && rm -f /etc/apache2/mods-enabled/mpm_worker.load \
 && rm -f /etc/apache2/mods-enabled/mpm_prefork.load \
 && rm -f /etc/apache2/mods-available/mpm_event.load \
 && rm -f /etc/apache2/mods-available/mpm_worker.load

# Habilitar SOLO prefork
RUN a2enmod mpm_prefork

# ---- Apache + PHP ----
RUN a2enmod rewrite

RUN docker-php-ext-install mysqli pdo pdo_mysql

# Copiar app
COPY . /var/www/html/

# DocumentRoot /public
RUN sed -i 's#DocumentRoot /var/www/html#DocumentRoot /var/www/html/public#g' /etc/apache2/sites-available/000-default.conf \
 && sed -i 's#/var/www/#/var/www/html/#g' /etc/apache2/apache2.conf \
 && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Puerto Railway
EXPOSE 8080
CMD ["bash", "-lc", "sed -i \"s/Listen 80/Listen ${PORT:-8080}/g\" /etc/apache2/ports.conf && apache2-foreground"]
