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
        $this->assertSame(['nuevas' => 1, 'actualizadas' => 0, 'filas' => 2], $r);
        (new Importador)->airbnb(self::AIRBNB);

        $res = Reserva::sole();
        $this->assertSame('2026-10-19', $res->check_in->toDateString());
        $this->assertSame(2100.0, $res->bruto);
        $this->assertSame(60.0, $res->comision);
        $this->assertSame(1900.0, $res->neto);
        $this->assertSame('Habitación Azul', Cuarto::find($res->cuarto_id)->anuncio_airbnb);
    }

    public function test_importa_gastos_de_excel(): void
    {
        $csv = "fecha,monto,categoria,descripcion,cuarto,factura,personal\n"
            ."05/10/2026,\"$1,450.50\",luz,CFE,,si,\n"
            ."18/10/2026,800,Mantenimiento,Llave,Cuarto 3,,\n"
            ."20/10/2026,300,Otros,Doctor,,si,si\n"
            .",5,x,sin fecha,,,\n";
        $r = (new Importador)->gastos($csv);

        $this->assertSame(['importados' => 3, 'omitidos' => 1], $r);
        $luz = Gasto::where('categoria', 'Luz')->sole();
        $this->assertSame('2026-10-05', $luz->fecha->toDateString());
        $this->assertSame(1450.5, $luz->monto);
        $this->assertTrue($luz->factura);
        $this->assertSame(3, Gasto::where('descripcion', 'Llave')->sole()->cuarto_id);
        $this->assertTrue(Gasto::where('descripcion', 'Doctor')->sole()->personal);
    }
}
