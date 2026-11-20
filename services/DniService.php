<?php
final class DniService {
    public function lookup(string $dni): array {
        if (!preg_match('/^\d{8}$/', $dni)) throw new ApiError('El DNI debe tener 8 dígitos.', 422, 'DNI_INVALIDO');
        $token = cfg('RENIEC_TOKEN');
        if ($token === '') throw new ApiError('Consulta DNI no configurada. Ingrese el nombre manualmente o configure el token gratuito de Decolecta.', 503, 'PROVEEDOR_DNI_NO_DISPONIBLE');

        $data = externalJson('https://api.decolecta.com/v1/reniec/dni?numero='.$dni, $token);
        $person = self::normalize($data, $dni);
        $_SESSION['identity'] = ['dni'=>$dni, 'nombre'=>$person['nombre'], 'expires'=>time()+600];
        return $person;
    }

    public static function normalize(array $data, string $requestedDni): array {
        $document = (string)($data['document_number'] ?? '');
        $name = trim((string)($data['full_name'] ?? ''));
        if ($document !== $requestedDni || $name === '' || mb_strlen($name) > 150) {
            throw new ApiError('El proveedor no devolvió datos coincidentes con el DNI solicitado.', 502, 'DNI_INVALIDO');
        }
        return [
            'dni'=>$document,
            'nombre'=>$name,
            'fuente'=>'Decolecta',
            'consultado'=>true,
            'identidad_confirmada'=>false
        ];
    }
}
