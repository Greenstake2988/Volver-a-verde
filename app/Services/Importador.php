<?php

namespace App\Services;

use App\Models\Cuarto;
use App\Models\Cuenta;
use App\Models\Deposito;
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
        // 1) El cuarto ligado a ese anuncio. Basta con el principio del nombre del anuncio
        //    ("Volver a Verde 4" liga "Volver a Verde 4| Loft Tropical..."), así que el cuarto
        //    se puede llamar como sea y si Airbnb cambia el título del anuncio se sigue reconociendo.
        $n = self::norm($anuncio);
        // Se guarda solo el nombre corto ("Volver a Verde 4| Loft..." → "Volver a Verde 4").
        $corto = Str::limit(trim(preg_split('/\s*(\||\s-\s)\s*/', $anuncio)[0]) ?: $anuncio, 200, '');
        foreach ($cuartos as $c) {
            $a = self::norm($c->anuncio_airbnb);
            if ($a !== '' && ($a === $n || (str_starts_with($n, $a) && ! ctype_digit(mb_substr($n, mb_strlen($a), 1))))) {
                return $c->id;
            }
        }
        foreach ($cuartos as $c) {
            if ($c->anuncio_airbnb === '' && self::norm($c->nombre) !== '' && str_contains($n, self::norm($c->nombre))) {
                $c->update(['anuncio_airbnb' => $corto]);

                return $c->id;
            }
        }
        // 2) "Volver a Verde 3| Loft..." va al cuarto que se llama "... 3" si todavía no tiene anuncio.
        $libres = $cuartos->where('anuncio_airbnb', '');
        $libre = null;
        if (preg_match('/\b(\d+)\b/', $anuncio, $m)) {
            $libre = $libres->first(fn ($c) => preg_match('/\b'.$m[1].'$/', trim($c->nombre)));
        }
        if ($libre) {
            $libre->update(['anuncio_airbnb' => $corto]);

            return $libre->id;
        }

        // 3) Anuncio nuevo: se crea su cuarto con el nombre corto; se puede renombrar después.
        return Cuarto::create(['nombre' => Str::limit($corto, 60, ''), 'anuncio_airbnb' => $corto])->id;
    }

    /**
     * "Historial de transacciones" de Airbnb (CSV en español o inglés).
     *
     * Cada reserva puede tener varios renglones con el mismo código de confirmación:
     * la reservación, el IVA que pagó el huésped ("Impuestos liquidados como anfitrión"),
     * las retenciones de IVA e ISR, cancelaciones parciales ("Ajuste") y reembolsos o
     * cobros de resoluciones. La suma de sus "Monto" es lo que Airbnb deposita, y los
     * renglones "Payout" son los depósitos reales al banco.
     */
    public function airbnb(string $texto): array
    {
        $filas = self::leerCsv($texto);
        $reservas = [];
        $depositos = [];
        foreach ($filas as $f) {
            $tipo = self::norm(self::col($f, 'tipo', 'type'));
            $monto = self::numero(self::col($f, 'monto', 'importe', 'amount'));

            if (in_array($tipo, ['payout', 'pago', 'transferencia'], true)) {
                $depositos[] = [
                    'fecha_envio' => self::fecha(self::col($f, 'fecha', 'date')),
                    'fecha' => self::fecha(self::col($f, 'fecha de llegada estimada', 'fecha de llegada prevista', 'arriving by date'))
                        ?? self::fecha(self::col($f, 'fecha', 'date')),
                    'monto' => self::numero(self::col($f, 'ingresos recibidos', 'pagado', 'paid out')),
                    'referencia' => self::col($f, 'codigo de referencia', 'reference code') ?: null,
                    'detalles' => mb_substr(self::col($f, 'detalles', 'details'), 0, 250),
                ];

                continue;
            }

            $codigo = self::col($f, 'codigo de confirmacion', 'confirmation code');
            $k = $codigo ?: 'sin-codigo-'.count($reservas);
            $r = &$reservas[$k];
            $r ??= ['codigo' => $codigo ?: null, 'huesped' => '', 'anuncio' => '', 'check_in' => null, 'fecha_salida' => null,
                'noches' => 0, 'bruto' => 0.0, 'comision' => 0.0, 'iva_trasladado' => 0.0, 'retencion_iva' => 0.0,
                'retencion_isr' => 0.0, 'ish' => 0.0, 'fecha_pago' => null];
            // Datos de la estancia: vienen en casi todos los renglones.
            $r['huesped'] = $r['huesped'] ?: self::col($f, 'huesped', 'guest');
            $r['anuncio'] = $r['anuncio'] ?: self::col($f, 'espacio', 'anuncio', 'listing');
            $r['check_in'] ??= self::fecha(self::col($f, 'fecha de inicio', 'start date'));
            $r['fecha_salida'] ??= self::fecha(self::col($f, 'fecha de finalizacion', 'end date'));
            $r['noches'] = $r['noches'] ?: (int) self::numero(self::col($f, 'noches', 'nights'));
            $fecha = self::fecha(self::col($f, 'fecha', 'date'));

            $tarifa = self::numero(self::col($f, 'tarifa de servicio', 'service fee'));
            $brutos = self::numero(self::col($f, 'ingresos brutos', 'ganancias brutas', 'gross earnings'));
            if (in_array($tipo, ['reservacion', 'reservation', 'reserva'], true)) {
                $bruto = $brutos ?: $monto + $tarifa;
                $r['bruto'] += $bruto;
                $r['comision'] += $bruto - $monto;       // incluye los centavos de redondeo de Airbnb
                $r['ish'] += self::numero(self::col($f, 'impuesto liquidado por airbnb', 'occupancy taxes'));
                $r['fecha_pago'] = max($r['fecha_pago'] ?? '', $fecha ?? '') ?: null;
            } elseif (in_array($tipo, ['ajuste', 'adjustment'], true)) {
                // Cancelación o cambio: la tarifa de servicio viene como lo que Airbnb devuelve de comisión.
                $r['comision'] -= $tarifa;
                $r['bruto'] += $monto - $tarifa;
                $r['ish'] -= self::numero(self::col($f, 'impuesto liquidado por airbnb', 'occupancy taxes'));
            } elseif (str_contains($tipo, 'impuestos liquidados') || str_contains($tipo, 'impuestos remitidos')
                || str_contains($tipo, 'host remitted') || str_contains($tipo, 'pass through')) {
                $r['iva_trasladado'] += $monto;
            } elseif ((str_contains($tipo, 'reten') || str_contains($tipo, 'withholding')) && (str_contains($tipo, 'iva') || str_contains($tipo, 'vat'))) {
                $r['retencion_iva'] -= $monto;           // la retención viene negativa y su revocación positiva
            } elseif ((str_contains($tipo, 'reten') || str_contains($tipo, 'withholding')) && (str_contains($tipo, 'renta') || str_contains($tipo, 'income'))) {
                $r['retencion_isr'] -= $monto;
            } else {
                // Cobros y ajustes de resoluciones (daños, reembolsos) y cualquier otro concepto.
                $r['bruto'] += $monto;
            }
            unset($r);
        }

        // Primero los anuncios en orden ("Volver a Verde 1", "2"...) para que los cuartos nuevos queden ordenados.
        $anuncios = array_unique(array_filter(array_column($reservas, 'anuncio')));
        natcasesort($anuncios);
        foreach ($anuncios as $anuncio) {
            $this->cuartoPorAnuncio($anuncio);
        }

        $nuevas = $actualizadas = $omitidas = 0;
        $totalNeto = 0.0;
        foreach ($reservas as $r) {
            if (! $r['check_in']) {
                $omitidas++;

                continue;
            }
            $datos = [
                'cuarto_id' => $this->cuartoPorAnuncio($r['anuncio']),
                'huesped' => $r['huesped'], 'check_in' => $r['check_in'], 'fecha_salida' => $r['fecha_salida'],
                'noches' => max(1, $r['noches']), 'fecha_pago' => $r['fecha_pago'], 'origen' => 'airbnb',
                'impuestos' => round($r['retencion_iva'] + $r['retencion_isr'], 2),
            ];
            foreach (['bruto', 'comision', 'iva_trasladado', 'retencion_iva', 'retencion_isr', 'ish'] as $c) {
                $datos[$c] = round($r[$c], 2);
            }
            $existente = $r['codigo'] ? Reserva::where('codigo', $r['codigo'])->first() : null;
            if ($existente) {
                $existente->update($datos);
                $actualizadas++;
            } else {
                $existente = Reserva::create($datos + ['codigo' => $r['codigo']]);
                $nuevas++;
            }
            $totalNeto += $existente->neto;
        }

        $depositosNuevos = 0;
        $totalDepositos = 0.0;
        foreach ($depositos as $d) {
            if (! $d['fecha'] || ! $d['monto']) {
                continue;
            }
            $totalDepositos += $d['monto'];
            $dep = $d['referencia']
                ? Deposito::firstOrCreate(['referencia' => $d['referencia']], $d)
                : Deposito::create($d);
            $depositosNuevos += (int) $dep->wasRecentlyCreated;
        }

        return ['nuevas' => $nuevas, 'actualizadas' => $actualizadas, 'omitidas' => $omitidas,
            'depositos' => $depositosNuevos, 'filas' => count($filas),
            'total_neto' => round($totalNeto, 2), 'total_depositos' => round($totalDepositos, 2)];
    }

    /** Columnas: fecha, monto, categoria y opcionales descripcion, cuarto, factura, personal, pagado con. */
    public function gastos(string $texto, ?int $userId = null): array
    {
        $cuartos = Cuarto::all()->mapWithKeys(fn ($c) => [self::norm($c->nombre) => $c->id]);
        $cuentas = Cuenta::orderBy('id')->get();
        $porDefecto = $cuentas->firstWhere('tipo', 'debito')?->id;
        // "Pagado con": nombre de la cuenta, o palabras como "credito", "debito", "propio"/"efectivo".
        $buscarCuenta = function (string $v) use ($cuentas, $porDefecto) {
            $v = self::norm($v);
            if ($v === '') {
                return $porDefecto;
            }
            if (in_array($v, ['propio', 'dinero propio', 'efectivo', 'personal', 'no'], true)) {
                return null;
            }
            return $cuentas->first(fn ($c) => self::norm($c->nombre) === $v)?->id
                ?? $cuentas->first(fn ($c) => str_contains($v, $c->tipo))?->id
                ?? $porDefecto;
        };
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
            Gasto::create([
                'fecha' => $fecha,
                'monto' => $monto,
                'categoria' => $cats[self::norm($cat)] ?? (Str::ucfirst(trim($cat)) ?: 'Otros'),
                'cuarto_id' => $cuartos[self::norm(self::col($f, 'cuarto', 'habitacion'))] ?? null,
                'descripcion' => self::col($f, 'descripcion', 'nota', 'notas', 'detalle'),
                'factura' => $si(self::col($f, 'factura', 'facturado', 'cfdi')),
                'personal' => $si(self::col($f, 'personal', 'no es del negocio')),
                'cuenta_id' => $buscarCuenta(self::col($f, 'pagado con', 'cuenta', 'tarjeta', 'de la cuenta')),
                'user_id' => $userId,
            ]);
            $n++;
        }

        return ['importados' => $n, 'omitidos' => $omitidos];
    }
}
