# ---------- PHP FPM ----------
FROM php:8.2-fpm

# Extensiones necesarias
RUN docker-php-ext-install mysqli pdo pdo_mysql

# ---------- NGINX ----------
RUN apt-get update && apt-get install -y nginx \
 && rm -rf /var/lib/apt/lists/*

# Configuración NGINX
RUN rm /etc/nginx/sites-enabled/default

RUN printf '%s\n' \
'server {' \
'  listen 8080;' \
'  root /var/www/html/public;' \
'  index index.php index.html;' \
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
> /etc/nginx/conf.d/default.conf

# ---------- APP ----------
COPY . /var/www/html/

WORKDIR /var/www/html

# ---------- START ----------
CMD ["sh", "-c", "php-fpm -D && nginx -g 'daemon off;'"]
