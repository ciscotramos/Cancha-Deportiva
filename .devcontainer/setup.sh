#!/usr/bin/env bash
# Prepara el Codespace: MariaDB, extensión mysqli, base de datos y usuario admin.
set -euo pipefail
cd "$(dirname "$0")/.."

# La imagen base trae un repositorio de Yarn con firma vencida que rompe apt-get update.
sudo rm -f /etc/apt/sources.list.d/yarn*.list
sudo apt-get update -q
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -q mariadb-server
# sudo borra PHP_INI_DIR; se pasa explícitamente para que la extensión quede activada.
if ! php -m 2>/dev/null | grep -qi '^mysqli$'; then
  sudo env PATH="$PATH" PHP_INI_DIR="${PHP_INI_DIR:-/usr/local/etc/php}" docker-php-ext-enable mysqli 2>/dev/null \
    || sudo env PATH="$PATH" PHP_INI_DIR="${PHP_INI_DIR:-/usr/local/etc/php}" docker-php-ext-install mysqli
fi

# El PHP de la imagen busca el socket en /tmp; MariaDB de Debian lo crea en /run/mysqld.
echo "mysqli.default_socket=/run/mysqld/mysqld.sock" \
  | sudo tee "${PHP_INI_DIR:-/usr/local/etc/php}/conf.d/zz-arena7-mysql.ini" >/dev/null

sudo service mariadb start
sudo mariadb < database/setup_arena7.sql
sudo mariadb -e "CREATE USER IF NOT EXISTS 'arena7'@'localhost' IDENTIFIED BY 'arena7_local';
GRANT ALL PRIVILEGES ON cancha_deportiva.* TO 'arena7'@'localhost'; FLUSH PRIVILEGES;"

php bin/migrate.php
composer install --no-interaction --quiet || echo "composer install falló (solo afecta el envío por Gmail)"
echo "Listo. Arena 7 quedará disponible en el puerto 8000."
