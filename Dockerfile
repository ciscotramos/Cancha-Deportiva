# Imagen única para el despliegue público (Render): PHP 8.2 + MariaDB + trabajador de notificaciones.
FROM php:8.2-cli-bookworm
# gd: Dompdf la necesita para el logo PNG del voucher en PDF; git: respaldo de Composer si falla la descarga zip.
RUN apt-get update \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends mariadb-server unzip git libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
 && docker-php-ext-configure gd --with-jpeg --with-freetype \
 && docker-php-ext-install mysqli gd \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY . /app
RUN (COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --no-progress || echo 'AVISO: composer install falló (PHPMailer/Dompdf no disponibles)') \
 && echo "mysqli.default_socket=/run/mysqld/mysqld.sock" > /usr/local/etc/php/conf.d/zz-arena7.ini \
 && chmod +x /app/deploy/render-start.sh
ENV DB_HOST=localhost DB_NAME=cancha_deportiva DB_USER=arena7 DB_PASSWORD=arena7_local \
    PRIVATE_DATA_OWNER=admin@arena7.com MP_MODE=prueba PHP_CLI_SERVER_WORKERS=4
EXPOSE 10000
CMD ["/app/deploy/render-start.sh"]
