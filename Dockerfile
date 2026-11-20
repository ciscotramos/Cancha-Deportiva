# Imagen única para el despliegue público (Render): PHP 8.2 + MariaDB + trabajador de notificaciones.
FROM php:8.2-cli-bookworm
RUN apt-get update \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends mariadb-server unzip \
 && docker-php-ext-install mysqli \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY . /app
RUN composer install --no-dev --no-interaction --quiet || true \
 && echo "mysqli.default_socket=/run/mysqld/mysqld.sock" > /usr/local/etc/php/conf.d/zz-arena7.ini \
 && chmod +x /app/deploy/render-start.sh
ENV DB_HOST=localhost DB_NAME=cancha_deportiva DB_USER=arena7 DB_PASSWORD=arena7_local \
    PRIVATE_DATA_OWNER=admin@arena7.com MP_MODE=prueba PHP_CLI_SERVER_WORKERS=4
EXPOSE 10000
CMD ["/app/deploy/render-start.sh"]
