#!/usr/bin/env bash
# Prepara el Codespace: MariaDB, extensión mysqli, base de datos y usuario admin.
set -euo pipefail
cd "$(dirname "$0")/.."

sudo apt-get update -q
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -q mariadb-server
php -m | grep -qi mysqli || sudo docker-php-ext-install mysqli

sudo service mariadb start
sudo mariadb < database/setup_arena7.sql
sudo mariadb -e "CREATE USER IF NOT EXISTS 'arena7'@'localhost' IDENTIFIED BY 'arena7_local';
GRANT ALL PRIVILEGES ON cancha_deportiva.* TO 'arena7'@'localhost'; FLUSH PRIVILEGES;"

php bin/migrate.php
composer install --no-interaction --quiet || echo "composer install falló (solo afecta el envío por Gmail)"
echo "Listo. Arena 7 quedará disponible en el puerto 8000."
