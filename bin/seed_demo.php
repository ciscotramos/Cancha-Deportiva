<?php
// Datos de demostración para el despliegue público. Solo actúa si no hay reservas;
// además aplica ADMIN_PASSWORD (si está definida) a la cuenta propietaria.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../services/bootstrap.php';
require __DIR__.'/../services/LegacyReservationService.php';

$owner = cfg('PRIVATE_DATA_OWNER', 'admin@arena7.com');
$clave = cfg('ADMIN_PASSWORD');
if ($clave !== '') {
    query($db, 'UPDATE usuarios SET password=? WHERE correo=?', [password_hash($clave, PASSWORD_DEFAULT), $owner]);
    echo "Contraseña del administrador tomada de ADMIN_PASSWORD.\n";
}
if ((int)$db->query('SELECT COUNT(*) FROM reservas')->fetch_row()[0] > 0) { echo "Ya hay reservas; no se cargan datos de demostración.\n"; exit; }

$user = ['correo'=>$owner, 'rol'=>'administrador'];
$svc = new LegacyReservationService($db);
$manana = date('Y-m-d', strtotime('+1 day'));
$pasado = date('Y-m-d', strtotime('+2 days'));
$demo = [
    ['45871236','QUISPE MAMANI LUIS ALBERTO','987112233','1',$manana,'19','Efectivo','','1'],
    ['70214589','FLORES TORRES MARIA ELENA','956778899','2',$manana,'19','Yape','YP-48213977','1'],
    ['41236987','RAMIREZ SOTO JORGE','912334455','3',$manana,'20','Plin','PL-99120345','1'],
    ['72589631','CASTILLO RUIZ ANDREA','945667788','1',$manana,'21','Efectivo','','1'],
    ['46987123','VARGAS LEON PEDRO','934556677','4',$pasado,'10','Efectivo','','1'],
];
foreach ($demo as [$dni,$cli,$tel,$can,$fec,$hor,$pago,$cod,$rec]) {
    $svc->create(['dni'=>$dni,'cliente'=>$cli,'telefono'=>$tel,'email'=>'','cancha'=>$can,'fecha'=>$fec,'hora'=>$hor,
        'pago'=>$pago,'codigoPago'=>$cod,'monto'=>TariffService::amount($hor),'pagoRecibido'=>$rec,'detalle'=>'Reserva de demostración'], $user);
}
echo count($demo)." reservas de demostración cargadas.\n";
