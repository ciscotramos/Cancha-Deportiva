<?php
// Prueba el servicio de cobro con Yape usando un proveedor simulado que cumple el
// contrato PasarelaPagoYape. Usa tablas temporales y no contacta a Mercado Pago.
putenv('MP_PUBLIC_KEY=TEST-'.str_repeat('a', 36));
putenv('MP_ACCESS_TOKEN=TEST-'.str_repeat('b', 60));
require __DIR__.'/../services/bootstrap.php';
require __DIR__.'/../services/LegacyReservationService.php';

function checkYape(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

/** Proveedor simulado: mismo contrato, respuestas de los números de prueba de Mercado Pago. */
final class PasarelaYapeSimulada implements PasarelaPagoYape {
    public array $llamadas = [];
    public function proveedor(): string { return 'Mercado Pago'; }
    public function configurada(): bool { return true; }
    public function modo(): string { return 'prueba'; }
    public function cobrarYape(string $monto, string $referencia, string $celular, string $otp,
                               string $correo, string $claveIdempotencia): array {
        $this->llamadas[] = compact('monto', 'referencia', 'celular', 'claveIdempotencia');
        $aprobado = $celular === '111111111';
        return ['id'=>(string)(1000 + count($this->llamadas)), 'estado'=>$aprobado ? 'aprobado' : 'rechazado',
                'detalle'=>MercadoPagoGateway::mensaje($aprobado ? 'accredited' : 'cc_rejected_call_for_authorize'),
                'monto'=>$monto];
    }
}

foreach (['reservas','pagos','pasarela_pagos','auditoria','notificaciones'] as $table) {
    $definition = $db->query("SHOW CREATE TABLE `$table`")->fetch_row()[1];
    $db->query(str_replace("CREATE TABLE `$table`", "CREATE TEMPORARY TABLE `$table`", $definition));
}
$user = ['correo'=>'prueba-yape@example.invalid', 'rol'=>'administrador'];
$input = [
    'dni'=>'12345678', 'cliente'=>'Prueba Yape', 'telefono'=>'987654321', 'cancha'=>'2',
    'fecha'=>date('Y-m-d', strtotime('+2 days')), 'hora'=>'19', 'pago'=>PagosAutomaticos::YAPE_MP,
    'codigoPago'=>'', 'monto'=>'1.00', 'detalle'=>'', 'email'=>'cliente@example.invalid',
];
$reservaId = (new LegacyReservationService($db))->create($input, $user);
$pago = query($db, 'SELECT * FROM pagos WHERE reserva_id=?', [$reservaId])->get_result()->fetch_assoc();
checkYape($pago['estado'] === 'Pendiente' && $pago['monto'] === '60.00', 'El importe nocturno no quedó fijado por el servidor.');

$sim = new PasarelaYapeSimulada();
$servicio = new YapePaymentService($db, $sim);

try { $servicio->cobrar($reservaId, '98765', '123456', $user); checkYape(false, 'Aceptó un celular inválido.'); }
catch (ApiError $e) { checkYape($e->status === 422, 'El celular inválido no devolvió 422.'); }
try { (new PaymentService($db))->confirm((int)$pago['id'], $user, 'manual'); checkYape(false, 'Permitió confirmar a mano un pago de pasarela.'); }
catch (ApiError $e) { checkYape($e->status === 409, 'La confirmación manual no se bloqueó.'); }

$rechazo = $servicio->cobrar($reservaId, '111111112', '123456', $user);
checkYape($rechazo['estado'] === 'rechazado', 'El rechazo no se informó.');
$reserva = query($db, 'SELECT estado FROM reservas WHERE id=?', [$reservaId])->get_result()->fetch_assoc();
checkYape($reserva['estado'] === 'Pendiente', 'Un rechazo cambió el estado de la reserva.');

$ok = $servicio->cobrar($reservaId, '111111111', '654321', $user);
checkYape($ok['estado'] === 'aprobado', 'El pago aprobado no se informó.');
checkYape($sim->llamadas[1]['monto'] === '60.00', 'El proveedor no recibió el importe del servidor.');
checkYape($sim->llamadas[0]['claveIdempotencia'] !== $sim->llamadas[1]['claveIdempotencia'], 'Un código nuevo reutilizó la clave de idempotencia.');
$reserva = query($db, 'SELECT estado FROM reservas WHERE id=?', [$reservaId])->get_result()->fetch_assoc();
$pago = query($db, 'SELECT estado,codigo,usuario FROM pagos WHERE reserva_id=?', [$reservaId])->get_result()->fetch_assoc();
checkYape($reserva['estado'] === 'Confirmado' && $pago['estado'] === 'Confirmado' && $pago['codigo'] === '1002', 'La reserva no quedó confirmada con la operación.');

$repetido = $servicio->cobrar($reservaId, '111111111', '654321', $user);
checkYape($repetido['estado'] === 'aprobado' && count($sim->llamadas) === 2, 'Una reserva pagada volvió a cobrarse.');
$acciones = array_column(query($db, 'SELECT accion FROM auditoria ORDER BY id')->get_result()->fetch_all(MYSQLI_ASSOC), 'accion');
checkYape(in_array('PAGO_RECHAZADO_PASARELA', $acciones, true) && in_array('PAGO_CONFIRMAR_PASARELA', $acciones, true), 'Faltan eventos de auditoría.');

echo "Cobro Yape (Mercado Pago): importe, rechazo, aprobación, idempotencia y auditoría verificados.\n";
