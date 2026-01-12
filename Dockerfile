# ===============================
# Base
# ===============================
FROM php:8.2-fpm

# ===============================
# Instalar dependencias
# ===============================
RUN apt-get update && apt-get install -y \
    nginx \
    gettext-base \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    curl \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install mysqli pdo pdo_mysql zip

# ===============================
# Configurar PHP
# ===============================
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# ===============================
# Eliminar MPM conflictivos
# ===============================
RUN rm -f /etc/apache2/mods-enabled/mpm_* || true

# ===============================
# Directorio de trabajo
# ===============================
WORKDIR /var/www/html

# ===============================
# Copiar proyecto
# ===============================
COPY . /var/www/html

# ===============================
# Permisos
# ===============================
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# ===============================
# NGINX config template
# ===============================
RUN rm -f /etc/nginx/sites-enabled/default

RUN printf '%s\n' \
'server {' \
'  listen ${PORT};' \
'  server_name _;' \
'' \
'  root /var/www/html/public;' \
'  index index.php index.html;' \
'' \
'  location / {' \
'    try_files $uri $uri/ /index.php?$query_string;' \
'  }' \
'' \
'  # ===== PRIVATE ROUTES =====' \
'  location ^~ /private/ {' \
'    alias /var/www/html/private/;' \
'    try_files $uri =404;' \
'' \
'    location ~ \.php$ {' \
FROM php:8.2-fpm

# PHP extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql

# NGINX + envsubst (gettext-base)
RUN apt-get update && apt-get install -y nginx gettext-base \
 && rm -rf /var/lib/apt/lists/*

# App
COPY . /var/www/html/
WORKDIR /var/www/html

# NGINX template
RUN printf '%s\n' \
'server {' \
'  listen $PORT;' \
'  server_name _;' \
'' \
'  root /var/www/html/public;' \
'  index index.php index.html;' \
'' \
'  # Servir /assets desde /var/www/html/assets (fuera de /public)' \
'  location ^~ /assets/ {' \
'    alias /var/www/html/assets/;' \
'    try_files $uri =404;' \
'    access_log off;' \
'    expires 7d;' \
'  }' \
'' \
'  # Servir /private desde /var/www/html/private (fuera de /public)' \
'  location ^~ /private/ {' \
'    alias /var/www/html/private/;' \
'    try_files $uri =404;' \
'' \
'    location ~ \.php$ {' \
'      include fastcgi_params;' \
'      fastcgi_pass 127.0.0.1:9000;' \
'      fastcgi_index index.php;' \
'      fastcgi_param SCRIPT_FILENAME $request_filename;' \
'    }' \
'  }' \
'' \
'  location / {' \
'    try_files $uri $uri/ /index.php?$query_string;' \
'  }' \
'' \
'  location ~ \.php$ {' \
'    include fastcgi_params;' \
'    fastcgi_pass 127.0.0.1:9000;' \
'    fastcgi_index index.php;' \
'    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;' \
'  }' \
'}' \
> /etc/nginx/conf.d/default.conf.template

# Start
CMD ["sh", "-c", "echo \"PORT=$PORT\" && envsubst '$PORT' < /etc/nginx/conf.d/default.conf.template > /etc/nginx/conf.d/default.conf && php-fpm -D && nginx -g 'daemon off;'"]
