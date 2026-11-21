<?php
/**
 * Adaptador de correo para la API HTTPS de Brevo (POST /v3/smtp/email).
 * Se usa en la nube porque el plan gratuito de Render bloquea los puertos SMTP;
 * permite enviar a cualquier destinatario con un remitente validado en Brevo,
 * sin dominio propio. Cumple el mismo papel que GmailMailer dentro de SRV-004.
 */
class BrevoMailer {
    private const API = 'https://api.brevo.com/v3/smtp/email';

    public static function configured(): bool {
        return cfg('BREVO_API_KEY') !== '' && self::remitente() !== null;
    }

    /** Lee MAIL_FROM en formato "Nombre <correo>" o solo "correo". */
    public static function remitente(): ?array {
        $from = trim(cfg('MAIL_FROM'));
        if (preg_match('/^\s*(.*?)\s*<([^>]+)>\s*$/', $from, $m)) {
            $nombre = trim($m[1], " \"'");
            $correo = trim($m[2]);
        } else {
            $nombre = 'Arena 7';
            $correo = $from;
        }
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) return null;
        return ['name' => $nombre !== '' ? $nombre : 'Arena 7', 'email' => $correo];
    }

    public static function send(string $to, string $subject, string $text, ?string $html, ?string $qrPath, string $idempotencia, array $adjuntos = []): void {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Destinatario inválido.');
        $remitente = self::remitente();
        if (!self::configured() || $remitente === null) throw new RuntimeException('Brevo no está configurado: defina BREVO_API_KEY y MAIL_FROM.');
        // Brevo exige contenido HTML; si el aviso es solo texto se convierte conservando los saltos de línea.
        $cuerpoHtml = $html ?? '<!doctype html><html lang="es"><body style="font-family:Arial,sans-serif;line-height:1.5;color:#202124">'
            .nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')).'</body></html>';
        $payload = [
            'sender' => $remitente,
            'to' => [['email' => $to]],
            'subject' => $subject,
            'htmlContent' => $cuerpoHtml,
            'textContent' => $text,
            'headers' => ['IdempotencyKey' => $idempotencia],
        ];
        if ($qrPath !== null) {
            if (!is_readable($qrPath)) throw new RuntimeException('No se encuentra el QR de Yape para el correo.');
            // El QR viaja como adjunto; el HTML no usa imágenes cid: en este proveedor.
            $payload['htmlContent'] = str_replace(
                '<img src="cid:yape-qr" alt="Código QR para pagar con Yape" width="320" style="max-width:100%;height:auto">',
                '<b>El código QR para pagar con Yape va adjunto a este correo.</b>', $payload['htmlContent']);
            $payload['attachment'] = [['name' => 'yape-qr.png', 'content' => base64_encode(file_get_contents($qrPath))]];
        }
        foreach ($adjuntos as $a) {
            $payload['attachment'][] = ['name' => $a['name'], 'content' => base64_encode($a['content'])];
        }
        $data = externalJson(self::API, cfg('BREVO_API_KEY'), $payload, [], 'api-key: ');
        if (empty($data['messageId'])) throw new ApiError('Brevo no confirmó la aceptación del mensaje.', 502);
    }
}
