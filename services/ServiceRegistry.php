<?php
/**
 * Registro de servicios de Arena 7 (catálogo interno con la función de un registro UDDI):
 * permite descubrir qué servicios existen y consultar su descripción técnica
 * (operaciones, contrato, seguridad y proveedores). No ejecuta operaciones ni guarda datos de negocio.
 */
final class ServiceRegistry {
    public const CONTRATO = '/docs/openapi.yaml';

    public static function catalogo(): array {
        $mp = new MercadoPagoGateway();
        return [
            self::servicio('SRV-001', 'Servicio de Autenticación', 'Seguridad', 'Utilidad', 'Nivel 2 · Servicios',
                'Verifica la identidad del personal, abre y cierra la sesión y protege cada operación con CSRF.',
                [['GET','/api.php/auth/captcha','Obtener CAPTCHA'], ['POST','/api.php/auth/login','Iniciar sesión'],
                 ['GET','/api.php/auth/me','Consultar sesión y token CSRF'], ['POST','/api.php/auth/logout','Cerrar sesión']],
                'HTTPS + autenticación (sesión, CAPTCHA, bcrypt) + autorización por rol', [], true),
            self::servicio('SRV-002', 'Servicio de Reserva de Cancha', 'Reservas', 'Entidad', 'Nivel 2 · Servicios',
                'Crea, edita, lista y cancela reservas; bloquea el horario 10 minutos y consulta la identidad del cliente por DNI.',
                [['GET','/api.php/reservas','Listar reservas'], ['POST','/api.php/reservas','Crear reserva pendiente'],
                 ['PUT','/api.php/reservas/{id}','Actualizar reserva'], ['DELETE','/api.php/reservas/{id}','Cancelar reserva'],
                 ['POST','/api.php/reservas/dni','Consultar identidad por DNI (RENIEC)'],
                 ['POST','/api.php/reservas/contacto','Sugerir último celular registrado']],
                'HTTPS + autenticación + autorización (propietario) + CSRF',
                [['nombre'=>'Decolecta (RENIEC)', 'configurado'=>cfg('RENIEC_TOKEN') !== '']], true),
            self::servicio('SRV-003', 'Servicio de Gestión de Pagos', 'Financiero', 'Tarea', 'Nivel 2 · Servicios',
                'Registra pagos en efectivo, Yape o Plin, cobra con Yape mediante Mercado Pago y confirma la reserva solo con pago válido.',
                [['GET','/api.php/pagos','Listar pagos'], ['POST','/api.php/pagos/{id}/confirmar','Confirmar pago manual'],
                 ['POST','/api.php/reservas/{id}/yape','Cobrar con Yape (Mercado Pago)']],
                'HTTPS + autenticación + autorización (administrador) + CSRF + idempotencia',
                [['nombre'=>'Mercado Pago (Yape)', 'configurado'=>$mp->configurada(), 'modo'=>$mp->modo()]], true),
            self::servicio('SRV-004', 'Servicio de Notificaciones', 'Comunicaciones', 'Utilidad', 'Nivel 2 · Servicios',
                'Encola y envía por correo la solicitud de pago o la confirmación de la reserva, con hasta 3 intentos.',
                [['GET','/api.php/notificaciones?reserva_id={id}','Consultar estado de envío']],
                'HTTPS + autenticación + autorización (propietario)',
                [['nombre'=>'Gmail SMTP / Brevo / Resend', 'configurado'=>NotificationService::provider() !== ''],
                 ['nombre'=>'Google Maps (enlace)', 'configurado'=>true]], true),
            self::servicio('SRV-005', 'Servicio de Auditoría', 'Control y trazabilidad', 'Utilidad', 'Nivel 2 · Servicios',
                'Registra cada evento en un historial de solo inserción encadenado con hash y permite verificar su integridad.',
                [['GET','/api.php/auditoria','Consultar historial con filtros'],
                 ['GET','/api.php/auditoria/verificar','Verificar la cadena de integridad']],
                'HTTPS + autenticación + autorización (administrador)', [], true),
            self::servicio('SRV-006', 'Registro de servicios', 'Gobierno SOA', 'Utilidad', 'Nivel 3 · Integración',
                'Publica el catálogo de servicios y su descripción técnica para su descubrimiento.',
                [['GET','/api.php/registro','Listar o buscar servicios (?q=)'], ['GET','/api.php/registro/{id}','Descripción técnica de un servicio']],
                'HTTPS + autenticación', [], true),
        ];
    }

    public static function buscar(string $texto): array {
        $texto = mb_strtolower(trim($texto));
        $servicios = self::catalogo();
        if ($texto === '') return ['total'=>count($servicios), 'contrato'=>self::CONTRATO, 'servicios'=>$servicios];
        $encontrados = array_values(array_filter($servicios, fn($s) =>
            str_contains(mb_strtolower($s['nombre'].' '.$s['dominio'].' '.$s['descripcion']), $texto)));
        return ['total'=>count($encontrados), 'contrato'=>self::CONTRATO, 'servicios'=>$encontrados];
    }

    public static function detalle(string $id): array {
        foreach (self::catalogo() as $s) if ($s['id'] === strtoupper($id)) return $s;
        throw new ApiError('Servicio no registrado.', 404, 'SERVICIO_NO_ENCONTRADO');
    }

    private static function servicio(string $id, string $nombre, string $dominio, string $tipo, string $capa,
                                     string $descripcion, array $operaciones, string $seguridad, array $proveedores, bool $activo): array {
        return [
            'id'=>$id, 'nombre'=>$nombre, 'version'=>'1.0', 'dominio'=>$dominio, 'tipo'=>$tipo, 'capa'=>$capa,
            'descripcion'=>$descripcion, 'estilo'=>'REST', 'formato'=>'JSON', 'transporte'=>'HTTPS (TLS)',
            'seguridad'=>$seguridad, 'estado'=>$activo ? 'Implementado' : 'Propuesto',
            'operaciones'=>array_map(fn($o)=>['metodo'=>$o[0], 'ruta'=>$o[1], 'descripcion'=>$o[2]], $operaciones),
            'proveedores_externos'=>$proveedores, 'contrato'=>self::CONTRATO,
        ];
    }
}
