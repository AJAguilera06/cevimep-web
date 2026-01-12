# ---------- PHP FPM ----------
FROM php:8.2-fpm

# PHP extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql

# ---------- NGINX ----------
RUN apt-get update && apt-get install -y nginx gettext-base \
 && rm -rf /var/lib/apt/lists/*

# App
COPY . /var/www/html/
WORKDIR /var/www/html

# NGINX template (usa $PORT)
RUN printf '%s\n' \
'server {' \
'  listen $PORT;' \
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
> /etc/nginx/conf.d/default.conf.template

# ---------- START ----------
CMD ["sh", "-c", "echo \"PORT=$PORT\" && envsubst '$PORT' < /etc/nginx/conf.d/default.conf.template > /etc/nginx/conf.d/default.conf && php-fpm -D && nginx -g 'daemon off;'"]
