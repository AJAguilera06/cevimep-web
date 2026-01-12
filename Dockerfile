FROM php:8.2-apache

# ---------- FIX DEFINITIVO MPM ----------
# 1) Quitar cualquier enable previo
RUN rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf || true

# 2) Borrar las definiciones disponibles de event/worker (por si acaso)
RUN rm -f /etc/apache2/mods-available/mpm_event.* /etc/apache2/mods-available/mpm_worker.* || true

# 3) BORRAR los binarios .so (esto es lo que lo hace 100% imposible de cargar)
RUN rm -f /usr/lib/apache2/modules/mod_mpm_event.so /usr/lib/apache2/modules/mod_mpm_worker.so || true

# 4) Habilitar SOLO prefork
RUN a2enmod mpm_prefork

# ---------- Apache + PHP ----------
RUN a2enmod rewrite
RUN docker-php-ext-install mysqli pdo pdo_mysql

# App
COPY . /var/www/html/

# DocumentRoot a /public
RUN sed -i 's#DocumentRoot /var/www/html#DocumentRoot /var/www/html/public#g' /etc/apache2/sites-available/000-default.conf \
 && sed -i 's#/var/www/#/var/www/html/#g' /etc/apache2/apache2.conf \
 && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Railway port
EXPOSE 8080
CMD ["bash", "-lc", "sed -i \"s/Listen 80/Listen ${PORT:-8080}/\" /etc/apache2/ports.conf && apache2-foreground"]
