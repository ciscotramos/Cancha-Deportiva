#!/bin/sh
# Espera a MariaDB, aplica la migración e inicia PHP (solo accesible dentro de Docker).
set -e
cd /app
until php -r 'new mysqli(getenv("DB_HOST"),getenv("DB_USER"),getenv("DB_PASSWORD"),getenv("DB_NAME"));' 2>/dev/null; do
  echo "Esperando a la base de datos..."; sleep 2
done
php bin/migrate.php
echo "Arena 7 listo en https://localhost:8443/"
exec php -d auto_prepend_file=/app/.devcontainer/https-codespaces.php -S 0.0.0.0:8000 -t /app
