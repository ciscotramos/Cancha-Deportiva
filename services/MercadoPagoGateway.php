<?php
/**
 * Adaptador del servicio externo de Mercado Pago (Checkout API, método Yape).
 * 1) Convierte celular + código de aprobación en un token de un solo uso.
 * 2) Crea el pago con ese token; Yape responde de inmediato aprobado o rechazado.
 * Documentación: https://www.mercadopago.com.pe/developers/es/docs/checkout-api-payments/integration-configuration/yape
 */
final class MercadoPagoGateway implements PasarelaPagoYape {
    private const API = 'https://api.mercadopago.com';

    public function proveedor(): string { return 'Mercado Pago'; }

    public function configurada(): bool {
        return preg_match('/^(TEST|APP_USR)-[A-Za-z0-9-]{20,}$/', cfg('MP_PUBLIC_KEY')) === 1
            && preg_match('/^(TEST|APP_USR)-[A-Za-z0-9-]{20,}$/', cfg('MP_ACCESS_TOKEN')) === 1;
    }

    public function modo(): string {
        return cfg('MP_MODE', 'prueba') === 'produccion' ? 'produccion' : 'prueba';
    }

    public function cobrarYape(string $monto, string $referencia, string $celular, string $otp,
                               string $correo, string $claveIdempotencia): array {
        if (!$this->configurada()) throw new ApiError('El cobro con Yape (Mercado Pago) todavía no está configurado.', 503);

        // Paso 1: token de Yape (solo usa la clave pública).
        [$code, $token] = $this->post(
            self::API.'/platforms/pci/yape/v1/payment?public_key='.rawurlencode(cfg('MP_PUBLIC_KEY')),
            ['Content-Type: application/json'],
            ['phoneNumber'=>$celular, 'otp'=>$otp, 'requestId'=>bin2hex(random_bytes(16))]
        );
        if ($code < 200 || $code >= 300 || !is_string($token['id'] ?? null) || $token['id'] === '') {
            error_log('Mercado Pago token Yape HTTP '.$code);
            throw new ApiError('Yape no aceptó el celular o el código de aprobación. Pida al cliente un código nuevo e intente otra vez.', 422);
        }

        // Paso 2: pago. El importe lo fija el servidor, nunca el navegador.
        [$code, $pago] = $this->post(
            self::API.'/v1/payments',
            ['Content-Type: application/json', 'Authorization: Bearer '.cfg('MP_ACCESS_TOKEN'),
             'X-Idempotency-Key: '.$claveIdempotencia],
            [
                'token'=>$token['id'],
                'transaction_amount'=>(float)$monto,
                'description'=>'Reserva Arena 7 '.$referencia,
                'installments'=>1,
                'payment_method_id'=>'yape',
                'external_reference'=>$referencia,
                'payer'=>['email'=>$correo],
            ]
        );
        if ($code === 401 || $code === 403) throw new ApiError('Las credenciales de Mercado Pago no son válidas.', 502);
        if ($code < 200 || $code >= 300 || !isset($pago['id'], $pago['status'])) {
            error_log('Mercado Pago pago HTTP '.$code.' '.json_encode($pago['cause'] ?? $pago['message'] ?? ''));
            throw new ApiError('Mercado Pago no pudo procesar el cobro. Intente otra vez.', 502);
        }

        $estado = match ($pago['status']) {
            'approved' => 'aprobado',
            'rejected', 'cancelled' => 'rechazado',
            default => 'en_proceso',
        };
        return [
            'id'=>(string)$pago['id'],
            'estado'=>$estado,
            'detalle'=>self::mensaje((string)($pago['status_detail'] ?? '')),
            'monto'=>number_format((float)($pago['transaction_amount'] ?? 0), 2, '.', ''),
        ];
    }

    /** Traduce el motivo de Mercado Pago a un mensaje para el operador. */
    public static function mensaje(string $detalle): string {
        return match ($detalle) {
            'accredited' => 'Pago acreditado.',
            'cc_rejected_call_for_authorize' => 'Yape no autorizó el pago. El cliente debe revisar su app.',
            'cc_amount_rate_limit_exceeded' => 'El importe supera el límite diario configurado en Yape.',
            'cc_rejected_max_attempts' => 'Se superaron los intentos permitidos. Pida un código nuevo.',
            'cc_rejected_bad_filled_security_code' => 'El código de aprobación es incorrecto o venció.',
            'cc_rejected_card_type_not_allowed', 'cc_unsupported_unsupported' => 'Yape no admite este pago.',
            'cc_rejected_form_error' => 'Los datos de Yape son inválidos.',
            default => 'Yape rechazó el pago.',
        };
    }

    /** @return array{0:int,1:array} */
    private function post(string $url, array $headers, array $body): array {
        $ch = curl_init($url);
        $options = [
            CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode($body, JSON_THROW_ON_ERROR),
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>30,
            CURLOPT_FOLLOWLOCATION=>false, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>$headers,
        ];
        if (cfg('CA_BUNDLE_PATH') !== '') $options[CURLOPT_CAINFO] = cfg('CA_BUNDLE_PATH');
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            error_log('Mercado Pago conexión: '.$error);
            throw new ApiError('No se pudo conectar con Mercado Pago; vuelva a intentar.', 502);
        }
        $json = json_decode($raw, true);
        return [$code, is_array($json) ? $json : []];
    }
}
