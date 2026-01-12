FROM php:8.2-apache

# ---------- LIMPIEZA TOTAL DE MPM ----------
RUN rm -f /etc/apache2/mods-enabled/mpm_* \
 && rm -f /etc/apache2/mods-available/mpm_event.load \
 && rm -f /etc/apache2/mods-available/mpm_worker.load

# Habilitar SOLO prefork
RUN a2enmod mpm_prefork

# ---------- LIMPIEZA DE CONF PERSONALIZADOS ----------
# Esto evita que algun .conf del proyecto cargue otro MPM
RUN rm -f /etc/apache2/conf-enabled/*.conf

# ---------- MODULOS NECESARIOS ----------
RUN a2enmod rewrite

# PHP extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql

# ---------- APP ----------
COPY . /var/www/html/

# ---------- DOCUMENT ROOT ----------
RUN sed -i 's#DocumentRoot /var/www/html#DocumentRoot /var/www/html/public#g' /etc/apache2/sites-available/000-default.conf \
 && sed -i 's#/var/www/#/var/www/html/#g' /etc/apache2/apache2.conf \
 && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# ---------- RAILWAY PORT ----------
EXPOSE 8080
CMD ["bash", "-lc", "sed -i \"s/Listen 80/Listen ${PORT:-8080}/\" /etc/apache2/ports.conf && apache2-foreground"]
