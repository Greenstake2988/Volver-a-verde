<?php

namespace App\Services;

use App\Models\Cuarto;
use App\Models\Gasto;
use App\Models\Reserva;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** Importa el CSV de Airbnb y el CSV de gastos exportado de Excel. */
class Importador
{
    public static function norm(?string $s): string
    {
        return Str::of((string) $s)->ascii()->lower()->trim()->toString();
    }

    public static function numero(?string $s): float
    {
        $s = trim(str_replace(['$', ',', 'MXN'], '', (string) $s));
        if (str_starts_with($s, '(') && str_ends_with($s, ')')) {
            $s = '-'.substr($s, 1, -1);
        }

        return is_numeric($s) ? (float) $s : 0.0;
    }

    /**
     * Airbnb exporta en formato de EE.UU. (mes/día); el Excel de México usa día/mes.
     * Con $diaPrimero se prueba primero día/mes.
     */
    public static function fecha(?string $s, bool $diaPrimero = false): ?string
    {
        $s = substr(trim((string) $s), 0, 10);
        $formatos = $diaPrimero
            ? ['d/m/Y', 'm/d/Y', 'Y-m-d', 'd-m-Y', 'd/m/y', 'm/d/y']
            : ['m/d/Y', 'd/m/Y', 'Y-m-d', 'd-m-Y', 'm/d/y', 'd/m/y'];
        foreach ($formatos as $fmt) {
            $d = \DateTime::createFromFormat('!'.$fmt, $s);
            if ($d && $d->format($fmt) === $s) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }

    /** @return array<int, array<string, string>> filas con encabezados normalizados */
    public static function leerCsv(string $texto): array
    {
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto);
        $muestra = substr($texto, 0, 2000);
        $delim = substr_count($muestra, ';') > substr_count($muestra, ',') ? ';' : ',';
        $lineas = preg_split('/\r\n|\n|\r/', trim($texto));
        $enc = array_map(fn ($h) => self::norm($h), str_getcsv(array_shift($lineas), $delim, '"', ''));
        $filas = [];
        foreach ($lineas as $l) {
            if (trim($l) === '') {
                continue;
            }
            $v = str_getcsv($l, $delim, '"', '');
            $fila = [];
            foreach ($enc as $i => $h) {
                if ($h !== '') {
                    $fila[$h] = trim($v[$i] ?? '');
                }
            }
            $filas[] = $fila;
        }

        return $filas;
    }

    private static function col(array $fila, string ...$nombres): string
    {
        foreach ($nombres as $n) {
            if (($fila[$n] ?? '') !== '') {
                return $fila[$n];
            }
        }

        return '';
    }

    private function cuartoPorAnuncio(string $anuncio): ?int
    {
        if ($anuncio === '') {
            return null;
        }
        $cuartos = Cuarto::orderBy('id')->get();
        foreach ($cuartos as $c) {
            if ($c->anuncio_airbnb !== '' && self::norm($c->anuncio_airbnb) === self::norm($anuncio)) {
                return $c->id;
            }
        }
        foreach ($cuartos as $c) {
            if (self::norm($c->nombre) !== '' && str_contains(self::norm($anuncio), self::norm($c->nombre))) {
                return $c->id;
            }
        }
        // Un anuncio nuevo se asigna al primer cuarto que todavía no tenga uno.
        $libre = $cuartos->firstWhere('anuncio_airbnb', '');
        $libre?->update(['anuncio_airbnb' => $anuncio]);

        return $libre?->id;
    }

    /** "Historial de transacciones" de Airbnb (español o inglés). */
    public function airbnb(string $texto): array
    {
        $filas = self::leerCsv($texto);
        $reservas = [];
        $retenciones = [];
        foreach ($filas as $f) {
            $tipo = self::norm(self::col($f, 'tipo', 'type'));
            $codigo = self::col($f, 'codigo de confirmacion', 'confirmation code');
            if (in_array($tipo, ['reservacion', 'reservation', 'reserva'], true)) {
                $k = $codigo ?: 'sin-codigo-'.count($reservas);
                $reservas[$k] ??= [
                    'codigo' => $codigo ?: null,
                    'huesped' => self::col($f, 'huesped', 'guest'),
                    'anuncio' => self::col($f, 'anuncio', 'listing'),
                    'check_in' => self::fecha(self::col($f, 'fecha de inicio', 'start date')),
                    'noches' => max(1, (int) self::numero(self::col($f, 'noches', 'nights'))),
                    'bruto' => 0.0, 'comision' => 0.0, 'impuestos' => 0.0, 'fecha_pago' => null,
                ];
                $importe = self::numero(self::col($f, 'importe', 'amount'));
                $comision = self::numero(self::col($f, 'tarifa de servicio', 'service fee'));
                $bruto = self::numero(self::col($f, 'ganancias brutas', 'gross earnings')) ?: $importe + $comision;
                $reservas[$k]['bruto'] += $bruto;
                $reservas[$k]['comision'] += $comision;
                $reservas[$k]['impuestos'] += max(0, $bruto - $comision - $importe);
                $reservas[$k]['fecha_pago'] = self::fecha(self::col($f, 'fecha', 'date')) ?? $reservas[$k]['fecha_pago'];
            } elseif (str_contains($tipo, 'reten') || str_contains($tipo, 'withholding') || in_array($tipo, ['impuesto', 'tax'], true)) {
                // Retenciones de ISR/IVA que Airbnb muestra como renglón aparte.
                $retenciones[] = [$codigo, abs(self::numero(self::col($f, 'importe', 'amount')))];
            }
        }
        foreach ($retenciones as [$codigo, $monto]) {
            if (isset($reservas[$codigo])) {
                $reservas[$codigo]['impuestos'] += $monto;
            }
        }

        $nuevas = $actualizadas = 0;
        foreach ($reservas as $r) {
            if (! $r['check_in']) {
                continue;
            }
            $datos = [
                'cuarto_id' => $this->cuartoPorAnuncio($r['anuncio']),
                'huesped' => $r['huesped'], 'check_in' => $r['check_in'], 'noches' => $r['noches'],
                'bruto' => round($r['bruto'], 2), 'comision' => round($r['comision'], 2),
                'impuestos' => round($r['impuestos'], 2), 'fecha_pago' => $r['fecha_pago'],
            ];
            $existente = $r['codigo'] ? Reserva::where('codigo', $r['codigo'])->first() : null;
            if ($existente) {
                $existente->update($datos);
                $actualizadas++;
            } else {
                Reserva::create($datos + ['codigo' => $r['codigo']]);
                $nuevas++;
            }
        }

        return ['nuevas' => $nuevas, 'actualizadas' => $actualizadas, 'filas' => count($filas)];
    }

    /** Columnas: fecha, monto, categoria y opcionales descripcion, cuarto, factura, personal, de la cuenta. */
    public function gastos(string $texto, ?int $userId = null): array
    {
        $cuartos = Cuarto::all()->mapWithKeys(fn ($c) => [self::norm($c->nombre) => $c->id]);
        $cats = collect(Gasto::CATEGORIAS)->mapWithKeys(fn ($c) => [self::norm($c) => $c]);
        $si = fn ($v) => in_array(self::norm($v), ['si', 's', 'x', '1', 'yes', 'true'], true);
        $n = $omitidos = 0;
        foreach (self::leerCsv($texto) as $f) {
            $fecha = self::fecha(self::col($f, 'fecha', 'date'), diaPrimero: true);
            $monto = abs(self::numero(self::col($f, 'monto', 'importe', 'total', 'cantidad', 'amount')));
            if (! $fecha || ! $monto) {
                $omitidos++;

                continue;
            }
            $cat = self::col($f, 'categoria', 'tipo', 'concepto');
            $cuenta = self::col($f, 'de la cuenta', 'cuenta del negocio');
            Gasto::create([
                'fecha' => $fecha,
                'monto' => $monto,
                'categoria' => $cats[self::norm($cat)] ?? (Str::ucfirst(trim($cat)) ?: 'Otros'),
                'cuarto_id' => $cuartos[self::norm(self::col($f, 'cuarto', 'habitacion'))] ?? null,
                'descripcion' => self::col($f, 'descripcion', 'nota', 'notas', 'detalle'),
                'factura' => $si(self::col($f, 'factura', 'facturado', 'cfdi')),
                'personal' => $si(self::col($f, 'personal', 'no es del negocio')),
                'de_la_cuenta' => $cuenta === '' ? true : $si($cuenta),
                'user_id' => $userId,
            ]);
            $n++;
        }

        return ['importados' => $n, 'omitidos' => $omitidos];
    }
}
