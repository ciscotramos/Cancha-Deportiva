#!/usr/bin/env bash
# Inicia MariaDB y el servidor PHP del Codespace en el puerto 8000.
cd "$(dirname "$0")/.."
sudo service mariadb start >/dev/null 2>&1 || true
pkill -f "php -S 0.0.0.0:8000" 2>/dev/null || true
# Codespaces entrega el sitio por HTTPS; el prepend avisa a PHP de que la conexión es segura.
nohup php -d auto_prepend_file="$PWD/.devcontainer/https-codespaces.php" \
  -S 0.0.0.0:8000 -t "$PWD" > /tmp/arena7.log 2>&1 &
echo "Arena 7 iniciado en el puerto 8000 (log: /tmp/arena7.log)"
