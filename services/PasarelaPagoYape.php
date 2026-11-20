<?php
/**
 * Contrato del servicio de pago con Yape (principio SOA: contrato estandarizado
 * y bajo acoplamiento). El resto del sistema solo conoce esta interfaz; el
 * proveedor concreto (hoy Mercado Pago) se puede reemplazar por otro adaptador
 * sin modificar reservas, pagos ni la interfaz de usuario.
 */
interface PasarelaPagoYape {
    /** Nombre del proveedor, para auditoría y mensajes. */
    public function proveedor(): string;

    /** Indica si las credenciales del proveedor están configuradas. */
    public function configurada(): bool;

    /** "prueba" o "produccion". */
    public function modo(): string;

    /**
     * Cobra un importe con Yape usando el celular y el código de aprobación del cliente.
     * La misma clave de idempotencia nunca genera un segundo cobro.
     *
     * @return array{id:string,estado:string,detalle:string,monto:string}
     *         estado: "aprobado", "rechazado" o "en_proceso".
     */
    public function cobrarYape(string $monto, string $referencia, string $celular, string $otp,
                               string $correo, string $claveIdempotencia): array;
}

/** Métodos de pago que solo confirma un proveedor externo, nunca el operador. */
final class PagosAutomaticos {
    public const QR = 'Pasarela QR';
    public const YAPE_MP = 'Yape (Mercado Pago)';

    public static function es(string $metodo): bool {
        return in_array($metodo, [self::QR, self::YAPE_MP], true);
    }
}
