<?php

namespace Tests\Feature;

use App\Models\Cuarto;
use App\Models\Cuenta;
use App\Models\Gasto;
use App\Models\Movimiento;
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

    public function test_caja_chica_retiros_gastos_en_efectivo_y_conteo(): void
    {
        $debito = Cuenta::where('tipo', 'debito')->first();
        $caja = Cuenta::caja();
        $this->assertNotNull($caja);   // la migración la crea
        $debito->update(['saldo_inicial' => 10000, 'fecha_saldo' => '2026-10-01']);
        $caja->update(['saldo_inicial' => 200, 'fecha_saldo' => '2026-10-01']);
        $admin = User::factory()->create(['admin' => true]);

        // Sacar 2,000 del banco para la caja: traspaso, no es salida del negocio.
        $this->actingAs($admin)->post(route('transferencias.store'), ['fecha' => '2026-10-05', 'monto' => 2000,
            'origen_id' => $debito->id, 'destino_id' => $caja->id])->assertRedirect()->assertSessionHas('ok');
        Gasto::create(['fecha' => '2026-10-06', 'monto' => 350, 'categoria' => 'Insumos', 'cuenta_id' => $caja->id]);
        $this->post(route('movimientos.store'), ['cuenta_id' => $caja->id, 'fecha' => '2026-10-07', 'monto' => 500, 'concepto' => 'Huésped pagó en efectivo']);

        $f = (new Finanzas)->flujo(CarbonImmutable::parse('2026-10-10'));
        $saldo = collect($f['cuentas'])->keyBy('tipo');
        $this->assertEqualsWithDelta(8000, $saldo['debito']['saldo'], 0.01);
        $this->assertEqualsWithDelta(200 + 2000 - 350 + 500, $saldo['efectivo']['saldo'], 0.01);
        $this->assertEqualsWithDelta(2350, $f['caja_chica'], 0.01);
        $this->assertEqualsWithDelta(500, $f['meses'][0]['entradas'], 0.01);   // el retiro no cuenta
        $this->assertEqualsWithDelta(350, $f['meses'][0]['salidas'], 0.01);
        $retiro = collect($f['movimientos'])->firstWhere('transferencia_id');
        $this->assertSame("{$debito->nombre} → {$caja->nombre}", $retiro['cuenta']);
        $this->assertStringContainsString('caja chica', $retiro['concepto']);
        // El gasto en efectivo sí cuenta en la utilidad.
        $this->assertEqualsWithDelta(350, (new Finanzas)->resumen($this->d('2026-10-01'), $this->d('2026-10-31'))['totales']['gastos'], 0.01);

        // Al contar la caja hay 2,300: faltaron 50 y queda un ajuste de salida.
        $this->post(route('caja.contar'), ['cuenta_id' => $caja->id, 'fecha' => '2026-10-10', 'contado' => 2300])->assertSessionHas('ok');
        $this->assertEqualsWithDelta(-50, Movimiento::latest('id')->first()->monto, 0.01);
        $saldo = collect((new Finanzas)->flujo(CarbonImmutable::parse('2026-10-10'))['cuentas'])->keyBy('tipo');
        $this->assertEqualsWithDelta(2300, $saldo['efectivo']['saldo'], 0.01);
        // Si cuadra, no se registra nada.
        $this->post(route('caja.contar'), ['cuenta_id' => $caja->id, 'fecha' => '2026-10-10', 'contado' => 2300]);
        $this->assertSame(2, Movimiento::count());
        // Solo se cuenta una caja de efectivo, y solo un administrador.
        $this->post(route('caja.contar'), ['cuenta_id' => $debito->id, 'fecha' => '2026-10-10', 'contado' => 1])->assertStatus(422);
        $this->actingAs(User::factory()->create(['admin' => false]))
            ->post(route('caja.contar'), ['cuenta_id' => $caja->id, 'fecha' => '2026-10-10', 'contado' => 0])->assertForbidden();
        $this->actingAs($admin)->get(route('flujo'))->assertOk()->assertSee('Contar la caja');
    }

    public function test_socio_registra_renta_en_efectivo_que_cuenta_como_ingreso_del_cuarto(): void
    {
        $caja = Cuenta::caja();
        $caja->update(['saldo_inicial' => 0, 'fecha_saldo' => '2026-10-01']);
        $cuarto = Cuarto::first();
        $socio = User::factory()->create(['admin' => false]);

        $this->actingAs($socio)->post(route('reservas.efectivo'), ['cuarto_id' => $cuarto->id, 'huesped' => 'Pedro',
            'check_in' => '2026-10-10', 'noches' => 2, 'monto' => 1800, 'fecha_pago' => '2026-10-10'])
            ->assertRedirect()->assertSessionHas('ok');

        $r = Reserva::sole();
        $this->assertSame('efectivo', $r->origen);
        $this->assertSame($socio->id, $r->capturado_por);
        // Cuenta en la utilidad y la ocupación del cuarto...
        $res = (new Finanzas)->resumen($this->d('2026-10-01'), $this->d('2026-10-31'));
        $this->assertEqualsWithDelta(1800, $res['totales']['neto'], 0.01);
        $this->assertSame(2, $res['totales']['noches']);
        $this->assertEqualsWithDelta(1800, collect($res['por_cuarto'])->firstWhere('id', $cuarto->id)['utilidad'], 0.01);
        // ...y el dinero entra a la caja chica, no al banco.
        $f = (new Finanzas)->flujo(CarbonImmutable::parse('2026-10-31'));
        $this->assertEqualsWithDelta(1800, collect($f['cuentas'])->firstWhere('id', $caja->id)['saldo'], 0.01);
        $this->assertStringContainsString('Renta en efectivo', $f['movimientos'][0]['concepto']);
        $this->actingAs($socio)->get(route('reservas.index'))->assertOk()->assertSee('Renta en efectivo');
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
