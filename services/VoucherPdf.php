<?php
/**
 * Genera el voucher de reserva en PDF (Dompdf) para adjuntarlo al correo de confirmación.
 * Reproduce el voucher que muestra el portal y no incluye el DNI del cliente.
 */
use Dompdf\Dompdf;
use Dompdf\Options;

final class VoucherPdf
{
    /** Datos esperados: id, cliente, cancha, fecha (Y-m-d), hora (0-23), pago, monto, estado, usuario, creado; opcionales: pago_id, operacion. */
    public static function render(array $r): string
    {
        if (!class_exists(Dompdf::class) && is_readable(__DIR__ . '/../vendor/autoload.php')) require_once __DIR__ . '/../vendor/autoload.php';
        if (!class_exists(Dompdf::class)) throw new RuntimeException('Dompdf no está instalado (composer install).');
        $opciones = new Options();
        $opciones->set('isRemoteEnabled', false);
        $opciones->set('defaultFont', 'DejaVu Sans');
        $opciones->setChroot(realpath(__DIR__ . '/..'));
        $pdf = new Dompdf($opciones);
        $pdf->loadHtml(self::html($r), 'UTF-8');
        $pdf->setPaper('A5', 'portrait');
        $pdf->render();
        return $pdf->output();
    }

    public static function nombreArchivo(int $reservaId): string
    {
        return 'Voucher_Arena7_Reserva_' . $reservaId . '.pdf';
    }

    private static function e($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function html(array $r): string
    {
        $e = [self::class, 'e'];
        $hora = (int)$r['hora'];
        $horario = sprintf('%s - %s', self::horaTexto($hora), self::horaTexto($hora + 1));
        $fecha = date('d/m/Y', strtotime($r['fecha']));
        $confirmada = ($r['estado'] ?? '') === 'Confirmado';
        $logo = 'data:image/png;base64,' . base64_encode((string)@file_get_contents(__DIR__ . '/../logo.png'));
        $local = VenueLocation::details();
        $filas = [
            ['N° de reserva', '#' . $r['id']],
            ['Cliente', $r['cliente']],
            ['Cancha', 'Cancha ' . $r['cancha'] . ' - Fútbol'],
            ['Fecha', $fecha],
            ['Horario', $horario],
            ['Método de pago', $r['pago']],
        ];
        if (!empty($r['operacion'])) $filas[] = ['Operación Mercado Pago', $r['operacion']];
        if ($confirmada && !empty($r['pago_id'])) $filas[] = ['Comprobante', 'PAGO-' . $r['pago_id']];
        $filas[] = ['Registrado por', $r['usuario'] ?? ''];
        $filas[] = ['Fecha de registro', $r['creado'] ?? ''];
        $tabla = '';
        foreach ($filas as [$k, $v]) $tabla .= '<tr><th>' . $e($k) . '</th><td>' . $e($v) . '</td></tr>';
        $estado = $confirmada ? 'RESERVA CONFIRMADA' : 'PAGO PENDIENTE';
        $claseEstado = $confirmada ? 'ok' : 'pend';
        $emitido = date('d/m/Y H:i');
        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><style>
            @page { margin: 10mm 11mm; }
            body { font-family: "DejaVu Sans", sans-serif; color: #2d3436; font-size: 9.5pt; }
            .cab { text-align: center; border-bottom: 2.5px solid #00b894; padding-bottom: 6px; }
            .cab img { width: 64px; }
            .cab h1 { font-size: 15pt; margin: 4px 0 0; color: #2d3436; }
            .estado { text-align: center; margin: 8px 0 7px; }
            .estado span { display: inline-block; padding: 4px 12px; border-radius: 12px; font-weight: bold; font-size: 9pt; letter-spacing: .5px; }
            .ok { background: #e6f9f3; color: #00895f; border: 1px solid #00b894; }
            .pend { background: #fff6e0; color: #a86b00; border: 1px solid #f0b429; }
            table { width: 100%; border-collapse: collapse; }
            th { text-align: left; width: 40%; padding: 4px 7px; background: #f4f7f6; border-bottom: 1px solid #e3e8e6; font-weight: bold; }
            td { padding: 4px 7px; border-bottom: 1px solid #e3e8e6; }
            .monto { margin: 8px 0; padding: 7px 11px; background: #00b894; color: #fff; border-radius: 6px; }
            .monto .k { font-size: 10pt; } .monto .v { font-size: 15pt; font-weight: bold; float: right; margin-top: -3px; }
            .ubic { margin-top: 6px; padding: 6px 10px; background: #eefaf6; border: 1px solid #c8eee2; border-radius: 6px; font-size: 8.5pt; }
            .ubic b { color: #00895f; } .ubic a { color: #00895f; }
            .pie { margin-top: 8px; font-size: 8pt; color: #636e72; text-align: center; line-height: 1.4; }
        </style></head><body>
            <div class="cab"><img src="' . $logo . '" alt="Arena 7"><h1>Voucher de Reserva</h1></div>
            <div class="estado"><span class="' . $claseEstado . '">' . $estado . '</span></div>
            <table>' . $tabla . '</table>
            <div class="monto"><span class="k">Monto ' . ($confirmada ? 'pagado' : 'a pagar') . '</span><span class="v">S/ ' . $e(number_format((float)$r['monto'], 2)) . '</span></div>
            <div class="ubic"><b>Ubicación de ' . $e($local['nombre']) . '</b><br>' . $e($local['local']) . '<br>' . $e($local['direccion'])
            . '<br><a href="' . $e($local['mapa']) . '">Ver en Google Maps</a>&nbsp;&nbsp;&nbsp;&nbsp;<a href="' . $e($local['como_llegar']) . '">Cómo llegar</a></div>
            <div class="pie">Presenta este voucher (impreso o en el celular) en la recepción de Arena 7.<br>
            Documento generado automáticamente por el sistema de reservas Arena 7 el ' . $e($emitido) . '.</div>
        </body></html>';
    }

    private static function horaTexto(int $h): string
    {
        $h = $h % 24;
        $sufijo = $h < 12 ? 'a. m.' : 'p. m.';
        $h12 = $h % 12 === 0 ? 12 : $h % 12;
        return sprintf('%02d:00 %s', $h12, $sufijo);
    }
}
