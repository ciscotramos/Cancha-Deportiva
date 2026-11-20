#!/bin/sh
# Arranque en la nube: base de datos local al contenedor, datos de demostración,
# trabajador de notificaciones y servidor PHP detrás del proxy HTTPS de Render.
set -e
cd /app
DATADIR="${MYSQL_DATADIR:-/var/lib/mysql}"
mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld 2>/dev/null || true
if [ ! -d "$DATADIR/mysql" ]; then
  mariadb-install-db --user=mysql --datadir="$DATADIR" --skip-test-db >/dev/null
fi
# Ajustes de memoria para el plan gratuito (512 MB).
mariadbd --user=mysql --datadir="$DATADIR" --socket=/run/mysqld/mysqld.sock --skip-networking \
  --innodb-buffer-pool-size=32M --performance-schema=OFF --max-connections=40 >/tmp/mariadb.log 2>&1 &
i=0; until mariadb --socket=/run/mysqld/mysqld.sock -e "SELECT 1" >/dev/null 2>&1; do
  i=$((i+1)); [ $i -gt 60 ] && { cat /tmp/mariadb.log; exit 1; }; sleep 1; done

mariadb --socket=/run/mysqld/mysqld.sock < database/setup_arena7.sql
mariadb --socket=/run/mysqld/mysqld.sock -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost'; FLUSH PRIVILEGES;"
php bin/migrate.php
php bin/seed_demo.php

# Mensajería asíncrona: procesa la cola de correos cada minuto.
( while true; do php bin/notificaciones.php >/dev/null 2>&1 || true; sleep 60; done ) &

echo "Arena 7 escuchando en el puerto ${PORT:-10000}"
exec php -d auto_prepend_file=/app/.devcontainer/https-codespaces.php -S 0.0.0.0:${PORT:-10000} -t /app
