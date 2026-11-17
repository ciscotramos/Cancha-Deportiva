<?php
// En Codespaces el túnel de GitHub termina el TLS y reenvía la petición por HTTP.
// Si la petición original llegó por HTTPS, se marca como segura para PHP.
if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    $_SERVER['HTTPS'] = 'on';
}
