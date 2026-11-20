<?php
/**
 * Servicio de negocio "Cobro con Yape". Orquesta la reserva, el pago y el
 * proveedor externo a través del contrato PasarelaPagoYape.
 */
final class YapePaymentService {
    public function __construct(private mysqli $db, private PasarelaPagoYape $pasarela) {}

    public function cobrar(int $reservaId, string $celular, string $otp, array $user): array {
        $celular = preg_replace('/\D/', '', $celular);
        if (!preg_match('/^\d{9}$/', $celular)) throw new ApiError('El celular de Yape debe tener 9 dígitos.');
        if (!preg_match('/^\d{6}$/', $otp)) throw new ApiError('El código de aprobación de Yape tiene 6 dígitos.');
        if (!$this->pasarela->configurada()) throw new ApiError('El cobro con Yape (Mercado Pago) todavía no está configurado.', 503);

        // Un solo cobro a la vez por reserva.
        $lock = 'arena7_yape_'.$reservaId;
        if (!query($this->db, 'SELECT GET_LOCK(?,10) AS l', [$lock])->get_result()->fetch_assoc()['l']) {
            throw new ApiError('Ya se está procesando un cobro para esta reserva.', 409);
        }
        try {
            $row = query($this->db,
                'SELECT p.id,p.metodo,p.monto,p.estado,r.estado AS reserva_estado,r.hold_until,r.email
                 FROM pagos p JOIN reservas r ON r.id=p.reserva_id WHERE r.id=? ORDER BY p.id DESC LIMIT 1',
                [$reservaId])->get_result()->fetch_assoc();
            if (!$row || $row['metodo'] !== PagosAutomaticos::YAPE_MP) throw new ApiError('Esta reserva no se cobra con Yape (Mercado Pago).', 404);
            if ($row['estado'] === 'Confirmado') return ['estado'=>'aprobado', 'mensaje'=>'Esta reserva ya estaba pagada.'];
            if ($row['estado'] !== 'Pendiente' || $row['reserva_estado'] !== 'Pendiente' || strtotime((string)$row['hold_until']) <= time()) {
                throw new ApiError('La reserva venció o fue cancelada; cree una nueva reserva.', 409);
            }

            $monto = number_format((float)$row['monto'], 2, '.', '');
            $referencia = 'ARENA7-'.$row['id'];
            query($this->db, "INSERT IGNORE INTO pasarela_pagos (pago_id,referencia,estado) VALUES (?,?,'Pendiente')", [$row['id'], $referencia]);
            $correo = filter_var($row['email'], FILTER_VALIDATE_EMAIL) ? $row['email'] : cfg('PRIVATE_DATA_OWNER', 'admin@arena7.com');
            // Reintentar con el mismo código devuelve el mismo resultado; un código nuevo es un intento nuevo.
            $clave = $referencia.'-'.substr(hash('sha256', $celular.$otp), 0, 16);

            $resultado = $this->pasarela->cobrarYape($monto, $referencia, $celular, $otp, $correo, $clave);
            return $this->registrar((int)$row['id'], $reservaId, $monto, $resultado);
        } finally {
            query($this->db, 'SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    private function registrar(int $pagoId, int $reservaId, string $monto, array $r): array {
        $actor = $this->pasarela->proveedor();
        return transaction($this->db, function () use ($pagoId, $reservaId, $monto, $r, $actor) {
            $pago = query($this->db, 'SELECT * FROM pagos WHERE id=? FOR UPDATE', [$pagoId])->get_result()->fetch_assoc();
            $reserva = query($this->db, 'SELECT * FROM reservas WHERE id=? FOR UPDATE', [$reservaId])->get_result()->fetch_assoc();
            query($this->db, 'UPDATE pasarela_pagos SET id_proveedor=? WHERE pago_id=?', [$r['id'], $pagoId]);
            $auditoria = new AuditService($this->db);

            if ($r['estado'] !== 'aprobado') {
                $accion = $r['estado'] === 'rechazado' ? 'PAGO_RECHAZADO_PASARELA' : 'PAGO_EN_PROCESO_PASARELA';
                $auditoria->record($actor, $accion, 'pagos', $pagoId, $r['detalle'].' Operación '.$r['id'].'.');
                return ['estado'=>$r['estado'], 'mensaje'=>$r['estado'] === 'rechazado'
                    ? $r['detalle'].' La reserva sigue pendiente; puede reintentar con un código nuevo.'
                    : 'El pago está en revisión en Mercado Pago. Verifique su estado antes de reintentar.'];
            }

            if ($r['monto'] !== $monto) {
                query($this->db, "UPDATE pasarela_pagos SET estado='Revisar' WHERE pago_id=?", [$pagoId]);
                $auditoria->record($actor, 'PAGO_PASARELA_TARDIO', 'pagos', $pagoId, 'El importe cobrado no coincide con la reserva; requiere revisión.');
                return ['estado'=>'revisar', 'mensaje'=>'El importe cobrado no coincide con la reserva. Revise la operación '.$r['id'].' en Mercado Pago.'];
            }
            if ($pago['estado'] !== 'Pendiente' || $reserva['estado'] !== 'Pendiente' || strtotime((string)$reserva['hold_until']) <= time()) {
                query($this->db, "UPDATE pasarela_pagos SET estado='Revisar' WHERE pago_id=?", [$pagoId]);
                $auditoria->record($actor, 'PAGO_PASARELA_TARDIO', 'pagos', $pagoId, 'Cobro aprobado después del vencimiento o cancelación; requiere revisión y posible devolución.');
                return ['estado'=>'revisar', 'mensaje'=>'El cobro se aprobó, pero la reserva ya había vencido. Revise la operación '.$r['id'].' para devolver el dinero.'];
            }

            query($this->db, "UPDATE pasarela_pagos SET estado='Confirmado' WHERE pago_id=?", [$pagoId]);
            query($this->db, "UPDATE pagos SET estado='Confirmado',codigo=?,usuario=? WHERE id=?", [$r['id'], $actor, $pagoId]);
            query($this->db, "UPDATE reservas SET estado='Confirmado',hold_until=NULL WHERE id=?", [$reservaId]);
            query($this->db, "UPDATE notificaciones SET estado='Cancelado' WHERE reserva_id=? AND evento='Recibida' AND estado IN ('Pendiente','Error')", [$reservaId]);
            (new NotificationService($this->db))->enqueue($reservaId);
            $auditoria->record($actor, 'PAGO_CONFIRMAR_PASARELA', 'pagos', $pagoId, 'Yape aprobado por '.$actor.'. Operación '.$r['id'].'.');
            $auditoria->record($actor, 'RESERVA_CONFIRMAR', 'reservas', $reservaId);
            return ['estado'=>'aprobado', 'operacion'=>$r['id'], 'mensaje'=>'Pago aprobado. Reserva confirmada.'];
        });
    }
}
