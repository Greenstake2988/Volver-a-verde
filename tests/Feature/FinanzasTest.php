<?php

namespace Tests\Feature;

use App\Models\Cuarto;
use App\Models\Cuenta;
use App\Models\Gasto;
use App\Models\Reserva;
use App\Models\Transferencia;
use App\Models\User;
use App\Services\Finanzas;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanzasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (range(1, 5) as $i) {
            Cuarto::create(['nombre' => "Cuarto {$i}"]);
        }
    }

    private function d(string $s): CarbonImmutable
    {
        return CarbonImmutable::parse($s);
    }

    public function test_noches_en_rango_reparte_reservas_que_cruzan_de_mes(): void
    {
        // Check-in 30 de octubre, 4 noches: 30 y 31 en octubre, 1 y 2 en noviembre.
        $this->assertSame(2, Finanzas::nochesEnRango($this->d('2026-10-30'), 4, $this->d('2026-10-01'), $this->d('2026-10-31')));
        $this->assertSame(2, Finanzas::nochesEnRango($this->d('2026-10-30'), 4, $this->d('2026-11-01'), $this->d('2026-11-30')));
        $this->assertSame(0, Finanzas::nochesEnRango($this->d('2026-12-01'), 2, $this->d('2026-11-01'), $this->d('2026-11-30')));
    }

    public function test_resumen_calcula_utilidad_ocupacion_y_excluye_gastos_que_no_son_del_negocio(): void
    {
        Reserva::create(['cuarto_id' => 1, 'check_in' => '2026-10-10', 'noches' => 3, 'bruto' => 3000, 'comision' => 90, 'impuestos' => 360]);
        Reserva::create(['cuarto_id' => 2, 'check_in' => '2026-10-30', 'noches' => 4, 'bruto' => 4000, 'comision' => 120, 'impuestos' => 480]);
        Gasto::create(['fecha' => '2026-10-05', 'monto' => 1000, 'categoria' => 'Luz']);
        Gasto::create(['fecha' => '2026-10-06', 'monto' => 500, 'categoria' => 'Mantenimiento', 'cuarto_id' => 1]);
        Gasto::create(['fecha' => '2026-10-07', 'monto' => 9999, 'categoria' => 'Otros', 'personal' => true]);

        $r = (new Finanzas)->resumen($this->d('2026-10-01'), $this->d('2026-10-31'));
        $t = $r['totales'];

        // Reserva 1 completa (neto 2550) + mitad de la reserva 2 (neto 3400 / 2 = 1700).
        $this->assertEqualsWithDelta(4250, $t['neto'], 0.01);
        $this->assertSame(5, $t['noches']);
        $this->assertEqualsWithDelta(1500, $t['gastos'], 0.01);
        $this->assertEqualsWithDelta(9999, $t['gastos_personales'], 0.01);
        $this->assertEqualsWithDelta(2750, $t['utilidad'], 0.01);
        $this->assertEqualsWithDelta(5 / (5 * 31), $t['ocupacion'], 0.0001);

        $cuarto1 = $r['por_cuarto'][0];
        $this->assertEqualsWithDelta(500, $cuarto1['gastos_directos'], 0.01);
        $this->assertEqualsWithDelta(200, $cuarto1['gastos_generales'], 0.01);   // 1000 / 5 cuartos
        $this->assertEqualsWithDelta(2550 - 500 - 200, $cuarto1['utilidad'], 0.01);
        $this->assertSame('Luz', array_key_first($r['por_categoria']));
    }

    public function test_el_ultimo_dia_del_periodo_cuenta(): void
    {
        Reserva::create(['cuarto_id' => 1, 'check_in' => '2026-10-31', 'noches' => 1, 'bruto' => 1000]);
        Gasto::create(['fecha' => '2026-10-31', 'monto' => 300, 'categoria' => 'Luz']);

        $t = (new Finanzas)->resumen($this->d('2026-10-01'), $this->d('2026-10-31'))['totales'];

        $this->assertSame(1, $t['noches']);
        $this->assertEqualsWithDelta(300, $t['gastos'], 0.01);
        $this->assertSame('2026-10-31', Gasto::sole()->getRawOriginal('fecha'));
    }

    public function test_flujo_por_cuenta_con_tarjeta_de_credito_y_reembolsos(): void
    {
        $debito = Cuenta::where('tipo', 'debito')->first();
        $credito = Cuenta::where('tipo', 'credito')->first();
        $debito->update(['saldo_inicial' => 10000, 'fecha_saldo' => '2026-10-01']);
        $credito->update(['saldo_inicial' => -500, 'fecha_saldo' => '2026-10-01']);   // se debían 500
        $socio = User::factory()->create(['name' => 'Juan']);

        Reserva::create(['cuarto_id' => 1, 'check_in' => '2026-10-10', 'noches' => 2, 'bruto' => 2000, 'fecha_pago' => '2026-10-11']);
        Reserva::create(['cuarto_id' => 1, 'check_in' => '2026-11-20', 'noches' => 2, 'bruto' => 1500, 'fecha_pago' => '2026-11-21']);
        Gasto::create(['fecha' => '2026-10-15', 'monto' => 700, 'categoria' => 'Gas', 'cuenta_id' => $debito->id]);
        Gasto::create(['fecha' => '2026-10-16', 'monto' => 1200, 'categoria' => 'Insumos', 'cuenta_id' => $credito->id]);
        Gasto::create(['fecha' => '2026-10-17', 'monto' => 650, 'categoria' => 'Gasolina', 'cuenta_id' => null, 'user_id' => $socio->id]);
        Gasto::create(['fecha' => '2026-09-30', 'monto' => 4000, 'categoria' => 'Luz', 'cuenta_id' => $debito->id]);   // antes del saldo inicial
        Transferencia::create(['fecha' => '2026-10-20', 'monto' => 1000, 'origen_id' => $debito->id, 'destino_id' => $credito->id]);
        Transferencia::create(['fecha' => '2026-10-25', 'monto' => 400, 'origen_id' => $debito->id, 'reembolso_a' => $socio->id]);

        $f = (new Finanzas)->flujo(CarbonImmutable::parse('2026-11-01'));
        $saldo = collect($f['cuentas'])->keyBy('tipo');

        $this->assertEqualsWithDelta(10000 + 2000 - 700 - 1000 - 400, $saldo['debito']['saldo'], 0.01);
        $this->assertEqualsWithDelta(-500 - 1200 + 1000, $saldo['credito']['saldo'], 0.01);   // debe 700
        $this->assertEqualsWithDelta(700, $f['deuda_tarjetas'], 0.01);
        $this->assertSame('Juan', $f['por_reembolsar'][0]['nombre']);
        $this->assertEqualsWithDelta(250, $f['total_reembolsar'], 0.01);
        $this->assertEqualsWithDelta(9900 - 700 - 250, $f['disponible'], 0.01);
        $this->assertEqualsWithDelta(1500, $f['por_cobrar'], 0.01);

        // El pago de la tarjeta no cuenta como salida del mes; la compra con tarjeta y el reembolso sí.
        $this->assertCount(1, $f['meses']);
        $this->assertEqualsWithDelta(2000, $f['meses'][0]['entradas'], 0.01);
        $this->assertEqualsWithDelta(700 + 1200 + 400, $f['meses'][0]['salidas'], 0.01);
    }

    public function test_gastos_de_ejemplo_sin_cuartos_y_sin_duplicar(): void
    {
        Cuarto::query()->delete();   // base nueva: todavía no se importa Airbnb
        $this->seed(\Database\Seeders\GastosDemoSeeder::class);
        $n = Gasto::count();
        $this->assertGreaterThan(0, $n);
        $this->seed(\Database\Seeders\GastosDemoSeeder::class);
        $this->assertSame($n, Gasto::count());
    }

    public function test_pago_de_tarjeta_sale_en_un_renglon_y_se_puede_borrar(): void
    {
        $debito = Cuenta::where('tipo', 'debito')->first();
        $credito = Cuenta::where('tipo', 'credito')->first();
        $debito->update(['saldo_inicial' => 10000, 'fecha_saldo' => '2026-10-01']);
        $credito->update(['saldo_inicial' => -3000, 'fecha_saldo' => '2026-10-01']);
        $t = Transferencia::create(['fecha' => '2026-10-20', 'monto' => 1000, 'origen_id' => $debito->id, 'destino_id' => $credito->id]);

        $f = (new Finanzas)->flujo(CarbonImmutable::parse('2026-11-01'));
        $this->assertCount(1, $f['movimientos']);
        $this->assertSame("{$debito->nombre} → {$credito->nombre}", $f['movimientos'][0]['cuenta']);
        $this->assertEqualsWithDelta(1000, $f['movimientos'][0]['monto'], 0.01);

        $admin = User::factory()->create(['admin' => true]);
        $this->actingAs($admin)->delete(route('transferencias.destroy', $t))->assertRedirect();
        $this->assertDatabaseCount('transferencias', 0);
        $saldo = collect((new Finanzas)->flujo(CarbonImmutable::parse('2026-11-01'))['cuentas'])->keyBy('tipo');
        $this->assertEqualsWithDelta(10000, $saldo['debito']['saldo'], 0.01);
        $this->assertEqualsWithDelta(-3000, $saldo['credito']['saldo'], 0.01);
    }

    public function test_socio_no_puede_borrar_pagos_de_tarjeta(): void
    {
        $debito = Cuenta::where('tipo', 'debito')->first();
        $credito = Cuenta::where('tipo', 'credito')->first();
        $t = Transferencia::create(['fecha' => '2026-10-20', 'monto' => 1000, 'origen_id' => $debito->id, 'destino_id' => $credito->id]);
        $this->actingAs(User::factory()->create(['admin' => false]))->delete(route('transferencias.destroy', $t))->assertForbidden();
        $this->assertDatabaseCount('transferencias', 1);
    }

    public function test_avisa_si_el_gasto_parece_duplicado(): void
    {
        $credito = Cuenta::where('tipo', 'credito')->first();
        $u = User::factory()->create(['admin' => true]);
        $datos = ['fecha' => now()->toDateString(), 'monto' => 618, 'categoria' => 'Luz', 'descripcion' => 'Lavandería', 'cuenta_id' => $credito->id];
        $this->actingAs($u)->post(route('gastos.store'), $datos)->assertSessionHas('ok', 'Gasto guardado.');
        $this->actingAs($u)->post(route('gastos.store'), $datos + ['personal' => 1])
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'ya había otro gasto'));
    }

    public function test_los_montos_se_muestran_con_centavos(): void
    {
        $this->assertSame('$1,450.50', dinero(1450.5));
        $this->assertSame('−$99.99', dinero(-99.99));
        $this->assertSame('$0.00', dinero(null));
    }
}
