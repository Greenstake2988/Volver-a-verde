<?php

namespace Tests\Feature;

use App\Models\Cuarto;
use App\Models\Gasto;
use App\Models\Reserva;
use App\Services\Importador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportadorTest extends TestCase
{
    use RefreshDatabase;

    private const AIRBNB = <<<'CSV'
Fecha,Fecha de llegada prevista,Tipo,Código de confirmación,Fecha de la reservación,Fecha de inicio,Noches,Huésped,Anuncio,Detalles,Código de referencia,Moneda,Importe,Pagado,Tarifa de servicio,Tarifa de pago rápido,Tarifa de limpieza,Ganancias brutas,Impuestos de ocupación,Año de ganancias
10/20/2026,10/21/2026,Pago,,,,,,,Transferencia,,MXN,,1900.00,,,,,,
10/20/2026,,Reservación,HMTEST1,10/01/2026,10/19/2026,2,Carla,Habitación Azul,,,MXN,1900.00,,60.00,,200.00,2100.00,,2026
CSV;

    public function test_importa_airbnb_sin_duplicar(): void
    {
        $r = (new Importador)->airbnb(self::AIRBNB);
        $this->assertSame([1, 0, 1, 2], [$r['nuevas'], $r['actualizadas'], $r['depositos'], $r['filas']]);
        (new Importador)->airbnb(self::AIRBNB);

        $res = Reserva::sole();
        $this->assertSame('2026-10-19', $res->check_in->toDateString());
        $this->assertSame(2100.0, $res->bruto);
        $this->assertSame(200.0, $res->comision);   // bruto − monto
        $this->assertSame(1900.0, $res->neto);
        $this->assertSame('Habitación Azul', Cuarto::find($res->cuarto_id)->anuncio_airbnb);
    }

    /** Mismo formato que el CSV real de Airbnb México (datos inventados). */
    private const AIRBNB_MX = <<<'CSV'
Fecha,Fecha de llegada estimada,Tipo,Código de confirmación,Fecha de la reservación,Fecha de inicio,Fecha de finalización,Noches,Huésped,Espacio,Detalles,Código de referencia,Moneda,Monto,Ingresos recibidos,Tarifa de servicio,Tarifa por Pago rápido,Tarifa de limpieza,Ingresos brutos,Impuesto liquidado por Airbnb,Año de ingresos
10/31/2026,11/06/2026,Payout,,,,,,,,"Transferir a Cuenta, Cheque 1111 (MXN)",REF001,MXN,,3420.95,,,,,,
10/31/2026,,Retención del IVA en México,HMAAA1,10/19/2026,10/30/2026,11/01/2026,2,Ana Prueba,"Volver a Verde 3| Loft Tropical, Terraza & Piscina",,,MXN,-160.16,,0.00,,0.00,,,2026
10/31/2026,,Retención del impuesto sobre la renta para México,HMAAA1,10/19/2026,10/30/2026,11/01/2026,2,Ana Prueba,"Volver a Verde 3| Loft Tropical, Terraza & Piscina",,,MXN,-80.08,,0.00,,0.00,,,2026
10/31/2026,,Impuestos liquidados como anfitrión,HMAAA1,10/19/2026,10/30/2026,11/01/2026,2,Ana Prueba,"Volver a Verde 3| Loft Tropical, Terraza & Piscina",,,MXN,320.32,,0.00,,0.00,320.32,,2026
10/31/2026,,Reservación,HMAAA1,10/19/2026,10/30/2026,11/01/2026,2,Ana Prueba,"Volver a Verde 3| Loft Tropical, Terraza & Piscina",,,MXN,1630.43,,371.57,,240.00,2002.00,90.09,2026
10/31/2026,,Reservación,HMBBB2,10/28/2026,10/30/2026,11/01/2026,2,Luis Prueba,Volver a Verde 1| Loft Tropical,,,MXN,1710.44,,371.56,,240.00,2082.00,90.00,2026
10/29/2026,,Ajuste de impuestos remitidos como anfitrión,HMCCC3,10/23/2026,10/27/2026,10/29/2026,2,Eva Prueba,Volver a Verde 1| Loft Tropical,,,MXN,-422.96,,0.00,,0.00,,,2026
10/29/2026,,Ajuste,HMCCC3,10/23/2026,10/27/2026,10/29/2026,2,Eva Prueba,Volver a Verde 1| Loft Tropical,,,MXN,-2152.86,,490.63,,0.00,,118.95,2026
10/29/2026,,Revocación de la retención del IVA para México,HMCCC3,10/23/2026,10/27/2026,10/29/2026,2,Eva Prueba,Volver a Verde 1| Loft Tropical,,,MXN,211.48,,0.00,,0.00,,,2026
10/29/2026,,Revocación de la retención del impuesto sobre la renta para México,HMCCC3,10/23/2026,10/27/2026,10/29/2026,2,Eva Prueba,Volver a Verde 1| Loft Tropical,,,MXN,105.74,,0.00,,0.00,,,2026
10/21/2026,,Cobro de la resolución,HMDDD4,,10/17/2026,10/19/2026,2,Leo Prueba,Volver a Verde 1| Loft Tropical,Cobro de la resolución,,MXN,400.00,,,,,400.00,,2026
CSV;

    public function test_importa_el_formato_real_de_airbnb_mexico(): void
    {
        $r = (new Importador)->airbnb("\u{FEFF}".self::AIRBNB_MX);   // con BOM, como lo baja Airbnb

        $this->assertSame(4, $r['nuevas']);
        $this->assertSame(1, $r['depositos']);

        $a = Reserva::where('codigo', 'HMAAA1')->sole();
        $this->assertSame(2002.0, $a->bruto);
        $this->assertSame(320.32, $a->iva_trasladado);
        $this->assertSame(371.57, $a->comision);
        $this->assertSame(160.16, $a->retencion_iva);
        $this->assertSame(80.08, $a->retencion_isr);
        $this->assertSame(240.24, $a->impuestos);
        $this->assertSame(90.09, $a->ish);
        $this->assertSame(1710.51, $a->neto);                       // 1630.43 + 320.32 − 160.16 − 80.08
        $this->assertSame('2026-11-01', $a->fecha_salida->toDateString());
        $this->assertSame('airbnb', $a->origen);

        // Sin cuartos dados de alta, se crea uno por anuncio con el nombre corto.
        $this->assertSame('Volver a Verde 3', $a->cuarto->nombre);
        $this->assertStringStartsWith('Volver a Verde 3', $a->cuarto->anuncio_airbnb);
        $this->assertSame('Volver a Verde 1', Reserva::where('codigo', 'HMBBB2')->sole()->cuarto->nombre);

        // Cancelación: el ajuste solo, sin su reservación original en el archivo.
        $c = Reserva::where('codigo', 'HMCCC3')->sole();
        $this->assertEqualsWithDelta(-2152.86 - 422.96 + 211.48 + 105.74, $c->neto, 0.01);
        $this->assertEqualsWithDelta(400.0, Reserva::where('codigo', 'HMDDD4')->sole()->neto, 0.01);

        // El depósito llega en la fecha estimada de llegada, no en la de envío.
        $this->assertSame(3420.95, \App\Models\Deposito::sole()->monto);
        $this->assertSame('2026-11-06', \App\Models\Deposito::sole()->fecha->toDateString());

        // Volver a importar no duplica.
        $r2 = (new Importador)->airbnb(self::AIRBNB_MX);
        $this->assertSame([0, 4, 0], [$r2['nuevas'], $r2['actualizadas'], $r2['depositos']]);
    }

    public function test_asigna_volver_a_verde_n_al_cuarto_n_si_ya_existen(): void
    {
        foreach (range(1, 5) as $i) {
            Cuarto::create(['nombre' => "Cuarto {$i}"]);
        }
        (new Importador)->airbnb("\u{FEFF}".self::AIRBNB_MX);

        // "Volver a Verde 3" va al Cuarto 3 aunque aparezca antes que el 1, y no se crean cuartos.
        $this->assertSame(5, Cuarto::count());
        $this->assertSame('Cuarto 3', Reserva::where('codigo', 'HMAAA1')->sole()->cuarto->nombre);
        $this->assertSame('Cuarto 1', Reserva::where('codigo', 'HMBBB2')->sole()->cuarto->nombre);
    }

    public function test_importa_gastos_de_excel(): void
    {
        foreach (range(1, 5) as $i) {
            Cuarto::create(['nombre' => "Cuarto {$i}"]);
        }
        $csv = "fecha,monto,categoria,descripcion,cuarto,factura,personal,pagado con\n"
            ."05/10/2026,\"$1,450.50\",luz,CFE,,si,,\n"
            ."18/10/2026,800,Mantenimiento,Llave,Cuarto 3,,,Crédito\n"
            ."20/10/2026,300,Otros,Doctor,,si,si,propio\n"
            .",5,x,sin fecha,,,,\n";
        $r = (new Importador)->gastos($csv);

        $this->assertSame(['importados' => 3, 'omitidos' => 1], $r);
        $luz = Gasto::where('categoria', 'Luz')->sole();
        $this->assertSame('2026-10-05', $luz->fecha->toDateString());
        $this->assertSame(1450.5, $luz->monto);
        $this->assertTrue($luz->factura);
        $this->assertSame(3, Gasto::where('descripcion', 'Llave')->sole()->cuarto_id);
        $this->assertTrue(Gasto::where('descripcion', 'Doctor')->sole()->personal);
        $this->assertSame('debito', $luz->cuenta->tipo);
        $this->assertSame('credito', Gasto::where('descripcion', 'Llave')->sole()->cuenta->tipo);
        $this->assertNull(Gasto::where('descripcion', 'Doctor')->sole()->cuenta_id);
    }
}
