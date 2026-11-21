<?php
date_default_timezone_set('America/Lima');
class ApiError extends RuntimeException {
    /** Código estable del contrato del servicio (sección 7 del informe); por defecto se deriva del estado HTTP. */
    public function __construct(string $message,public int $status=422,public string $codigo=''){
        parent::__construct($message);
        if($this->codigo==='') $this->codigo=self::codigoPorEstado($status);
    }
    public static function codigoPorEstado(int $status): string {
        return match(true){
            $status===401=>'SESION_INVALIDA', $status===403=>'ACCESO_DENEGADO', $status===404=>'NO_ENCONTRADO',
            $status===405=>'METODO_HTTP_NO_PERMITIDO', $status===409=>'CONFLICTO', $status===429=>'LIMITE_INTENTOS',
            $status>=502=>'PROVEEDOR_NO_DISPONIBLE', $status>=500=>'ERROR_INTERNO', default=>'DATOS_INVALIDOS',
        };
    }
}
function cfg(string $key,string $default=''): string {return getenv($key)!==false?getenv($key):$default;}
function respond($value,int $status=200): never {http_response_code($status);echo json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
function query(mysqli $db,string $sql,array $params=[]): mysqli_stmt {
    $s=$db->prepare($sql);if($params) $s->bind_param(str_repeat('s',count($params)),...$params);$s->execute();return $s;
}
function transaction(mysqli $db,callable $fn) {$db->begin_transaction();try {$r=$fn();$db->commit();return $r;}catch(Throwable $e){$db->rollback();throw $e;}}
function externalJson(string $url,string $token,?array $data=null,array $extra=[],string $authHeader='Authorization: Bearer '): array {
    if(!$token) throw new ApiError('Este proveedor aún no está configurado.',503);
    $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>array_merge([$authHeader.$token,'Content-Type: application/json','Accept: application/json'],$extra)];
    $caBundle=cfg('CA_BUNDLE_PATH');
    if($caBundle!=='') {
        if(!is_readable($caBundle)) throw new ApiError('No se encuentra el paquete de certificados TLS del servidor.',503);
        $options[CURLOPT_CAINFO]=$caBundle;
    }
    $ch=curl_init($url);curl_setopt_array($ch,$options);
    if($data!==null){curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($data));}
    $raw=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);$curlError=curl_error($ch);curl_close($ch);
    if($raw===false) {error_log('Error de conexión con proveedor externo: '.$curlError);throw new ApiError('No se pudo establecer una conexión segura con el proveedor.',502);}
    // Motivo devuelto por el proveedor (p. ej. Resend: {"message": "..."}), recortado y sin saltos de línea.
    $motivo='';
    if($code<200||$code>=300){
        $err=json_decode((string)$raw,true);
        $msg=is_array($err)?($err['message']??($err['error']['message']??($err['error']??''))):'';
        if(is_string($msg)&&$msg!=='') $motivo=' Detalle del proveedor (HTTP '.$code.'): '.mb_substr(preg_replace('/\s+/',' ',$msg),0,220);
        else $motivo=' (HTTP '.$code.')';
    }
    if($code===401||$code===403) throw new ApiError('El proveedor rechazó la solicitud por credenciales o permisos.'.$motivo,502);
    if($code===429) throw new ApiError('Se alcanzó el límite de consultas del proveedor. Intente más tarde.'.$motivo,503);
    if($code<200||$code>=300) throw new ApiError('El proveedor no pudo completar la solicitud.'.$motivo,502);
    $result=json_decode($raw,true);if(!is_array($result)) throw new ApiError('Respuesta inválida del proveedor.',502);return $result;
}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli(cfg('DB_HOST','localhost'),cfg('DB_USER','root'),cfg('DB_PASSWORD'),cfg('DB_NAME','cancha_deportiva'));
$db->set_charset('utf8mb4');
// Perú no usa horario de verano: la BD usa la misma hora que PHP (America/Lima).
$db->query("SET time_zone='-05:00'");
if(PHP_SAPI!=='cli'){
    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
        http_response_code(426);
        header('Content-Type: text/plain; charset=utf-8');
        exit('HTTPS es obligatorio. Abra la dirección segura del sitio.');
    }
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','secure'=>true]);session_start();
}
require_once __DIR__.'/AuditService.php';
require_once __DIR__.'/AuthService.php';
require_once __DIR__.'/NotificationService.php';
require_once __DIR__.'/PaymentService.php';
require_once __DIR__.'/TariffService.php';
require_once __DIR__.'/TaypiGateway.php';
require_once __DIR__.'/GatewayPaymentService.php';
require_once __DIR__.'/PasarelaPagoYape.php';
require_once __DIR__.'/MercadoPagoGateway.php';
require_once __DIR__.'/YapePaymentService.php';
require_once __DIR__.'/ServiceRegistry.php';
require_once __DIR__.'/DniService.php';
require_once __DIR__.'/CustomerContactService.php';
require_once __DIR__.'/ReservationService.php';
