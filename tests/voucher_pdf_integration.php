<?php
// Verifica el voucher en PDF que se adjunta al correo de confirmación. Usa tablas temporales; no envía correos.
require __DIR__.'/../services/bootstrap.php';

foreach (['reservas', 'pagos'] as $table) {
    $definition = $db->query("SHOW CREATE TABLE `$table`")->fetch_row()[1];
    $db->query(str_replace("CREATE TABLE `$table`", "CREATE TEMPORARY TABLE `$table`", $definition));
}
function checkVoucher(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

query($db, "INSERT INTO reservas (dni,cliente,telefono,cancha,fecha,hora,pago,monto,usuario,estado) VALUES (?,?,?,?,?,?,?,?,?,?)",
    ['12345678', 'CLIENTE DE PRUEBA', '999888777', '1', date('Y-m-d', strtotime('+1 day')), '18', PagosAutomaticos::YAPE_MP, 60, 'admin@arena7.com', 'Confirmado']);
$reservaId = $db->insert_id;
query($db, "INSERT INTO pagos (reserva_id,metodo,codigo,monto,estado,usuario) VALUES (?,?,?,?,?,?)",
    [$reservaId, PagosAutomaticos::YAPE_MP, '1328859256', 60, 'Confirmado', 'admin@arena7.com']);

$service = new NotificationService($db);
$metodo = new ReflectionMethod($service, 'voucherAdjunto');
$metodo->setAccessible(true);
$adjuntos = $metodo->invoke($service, $reservaId);
checkVoucher(count($adjuntos) === 1, 'No se generó el voucher en PDF.');
$pdf = $adjuntos[0];
checkVoucher($pdf['name'] === "Voucher_Arena7_Reserva_$reservaId.pdf" && $pdf['type'] === 'application/pdf', 'Nombre o tipo del adjunto incorrecto.');
checkVoucher(str_starts_with($pdf['content'], '%PDF-') && strlen($pdf['content']) > 10000, 'El contenido no es un PDF válido.');

$html = VoucherPdf::html(query($db, 'SELECT r.*, 1 AS pago_id FROM reservas r WHERE id=?', [$reservaId])->get_result()->fetch_assoc() + ['operacion' => '1328859256']);
checkVoucher(str_contains($html, 'CLIENTE DE PRUEBA') && str_contains($html, '1328859256') && str_contains($html, 'RESERVA CONFIRMADA')
    && !str_contains($html, '12345678'), 'El voucher no muestra los datos esperados o expone el DNI.');

// El adaptador de Gmail adjunta el PDF al mensaje (sin enviarlo).
putenv('GMAIL_ADDRESS=operador@example.invalid');
putenv('GMAIL_APP_PASSWORD='.str_repeat('x', 16));
$mail = GmailMailer::compose('cliente@example.invalid', 'Confirmación', 'Texto', null, null, $adjuntos);
checkVoucher($mail->preSend(), 'No se pudo preparar el correo con el voucher.');
$mime = $mail->getSentMIMEMessage();
checkVoucher(str_contains($mime, 'application/pdf') && str_contains($mime, $pdf['name']), 'El correo no lleva el voucher adjunto.');

echo "Voucher PDF: generación, datos sin DNI y adjunto en el correo OK.\n";
