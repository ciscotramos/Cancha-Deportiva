<?php
class AuditService {
    public function __construct(private mysqli $db) {}

    /**
     * Guarda un evento de solo inserción encadenado con el anterior mediante SHA-256:
     * si alguien altera o borra un evento, la verificación de la cadena lo detecta.
     */
    public function record(string $user, string $action, string $resource, ?int $id = null, ?string $detail = null): void {
        if (!query($this->db, "SELECT GET_LOCK('arena7_auditoria',5) AS l")->get_result()->fetch_assoc()['l']) {
            throw new ApiError('No se pudo registrar la auditoría; vuelva a intentar.', 409, 'SISTEMA_OCUPADO');
        }
        try {
            $this->db->query('INSERT IGNORE INTO auditoria_cadena (id,ultimo_hash) VALUES (1,NULL)');
            $anterior = query($this->db, 'SELECT ultimo_hash FROM auditoria_cadena WHERE id=1 FOR UPDATE')
                ->get_result()->fetch_assoc()['ultimo_hash'];
            $creado = date('Y-m-d H:i:s');
            $hash = self::hash($anterior, $user, $action, $resource, $id, $detail, $creado);
            query($this->db,
                'INSERT INTO auditoria (usuario,accion,recurso,recurso_id,detalle,creado,hash,hash_anterior) VALUES (?,?,?,?,?,?,?,?)',
                [$user, $action, $resource, $id, $detail, $creado, $hash, $anterior]
            );
            query($this->db, 'UPDATE auditoria_cadena SET ultimo_hash=? WHERE id=1', [$hash]);
        } finally {
            query($this->db, "SELECT RELEASE_LOCK('arena7_auditoria')");
        }
    }

    public static function hash(?string $anterior, string $user, string $action, string $resource, $id, ?string $detail, string $creado): string {
        return hash('sha256', implode('|', [(string)$anterior, $user, $action, $resource, (string)($id ?? ''), (string)($detail ?? ''), $creado]));
    }

    /** Recalcula la cadena completa y devuelve el primer evento alterado, si lo hay. */
    public function verificar(): array {
        $filas = query($this->db, 'SELECT id,usuario,accion,recurso,recurso_id,detalle,creado,hash,hash_anterior FROM auditoria WHERE hash IS NOT NULL ORDER BY id')
            ->get_result()->fetch_all(MYSQLI_ASSOC);
        $sinHash = (int)query($this->db, 'SELECT COUNT(*) n FROM auditoria WHERE hash IS NULL')->get_result()->fetch_assoc()['n'];
        $previo = null;
        foreach ($filas as $f) {
            $esperado = self::hash($f['hash_anterior'], $f['usuario'], $f['accion'], $f['recurso'], $f['recurso_id'], $f['detalle'], $f['creado']);
            if (($previo !== null && $f['hash_anterior'] !== $previo) || !hash_equals($esperado, (string)$f['hash'])) {
                return ['integra'=>false, 'eventos_verificados'=>count($filas), 'eventos_sin_hash'=>$sinHash,
                        'primer_evento_alterado'=>(int)$f['id'], 'mensaje'=>'La cadena de auditoría fue alterada a partir del evento '.$f['id'].'.'];
            }
            $previo = $f['hash'];
        }
        $cabeza = query($this->db, 'SELECT ultimo_hash FROM auditoria_cadena WHERE id=1')->get_result()->fetch_assoc()['ultimo_hash'] ?? null;
        $integra = $previo === $cabeza;
        return ['integra'=>$integra, 'eventos_verificados'=>count($filas), 'eventos_sin_hash'=>$sinHash,
                'primer_evento_alterado'=>null, 'ultimo_hash'=>$previo,
                'mensaje'=>$integra ? 'La cadena de auditoría está íntegra.' : 'El último evento registrado no coincide con la cabeza de la cadena.'];
    }

    public function list(array $filters): array {
        $where = [];
        $params = [];
        if (isset($filters['usuario']) && trim((string)$filters['usuario']) !== '') {
            $where[] = 'usuario=?';
            $params[] = strtolower(trim((string)$filters['usuario']));
        }
        if (isset($filters['accion']) && trim((string)$filters['accion']) !== '') {
            $where[] = 'accion=?';
            $params[] = trim((string)$filters['accion']);
        }
        $resourceId = $filters['reserva_id'] ?? $filters['recurso_id'] ?? null;
        if ($resourceId !== null && $resourceId !== '') {
            $id = filter_var($resourceId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) throw new ApiError('ID de recurso inválido.');
            $where[] = 'recurso_id=?';
            $params[] = $id;
            if (isset($filters['reserva_id'])) $where[] = "recurso='reservas'";
        }
        foreach (['desde', 'hasta'] as $key) {
            if (empty($filters[$key])) continue;
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$filters[$key]);
            if (!$date || $date->format('Y-m-d') !== $filters[$key]) throw new ApiError('Fecha de auditoría inválida.');
            $where[] = $key === 'desde' ? 'creado>=?' : 'creado<?';
            $params[] = $key === 'desde' ? $date->format('Y-m-d') : $date->modify('+1 day')->format('Y-m-d');
        }
        if (!empty($filters['before_id'])) {
            $before = filter_var($filters['before_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($before === false) throw new ApiError('Cursor de auditoría inválido.');
            $where[] = 'id<?';
            $params[] = $before;
        }
        $sql = 'SELECT id,usuario,accion,recurso,recurso_id,detalle,creado,hash FROM auditoria';
        if ($where) $sql .= ' WHERE '.implode(' AND ', $where);
        $sql .= ' ORDER BY id DESC LIMIT 100';
        return query($this->db, $sql, $params)->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}
