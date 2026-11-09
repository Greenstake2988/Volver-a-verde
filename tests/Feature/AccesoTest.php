<?php

namespace Tests\Feature;

use App\Models\Cuarto;
use App\Models\Gasto;
use App\Models\Reserva;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccesoTest extends TestCase
{
    use RefreshDatabase;

    public function test_sin_usuarios_pide_crear_el_administrador(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertRedirect('/primer-usuario');

        $this->post('/primer-usuario', ['name' => 'Oscar', 'usuario' => 'Oscar', 'password' => 'Secreto12345',
            'password_confirmation' => 'Secreto12345'])->assertRedirect('/ajustes');

        $this->assertTrue(User::where('usuario', 'oscar')->first()->admin);
        $this->assertAuthenticated();
        // Ya no se puede volver a usar.
        $this->post('/logout');
        $this->post('/primer-usuario', ['name' => 'X', 'usuario' => 'x', 'password' => 'Secreto12345',
            'password_confirmation' => 'Secreto12345'])->assertForbidden();
        $this->assertSame(1, User::count());
    }

    public function test_login_y_limite_de_intentos(): void
    {
        User::factory()->create(['usuario' => 'juan', 'password' => 'correcta123']);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['usuario' => 'juan', 'password' => 'mala'])->assertSessionHasErrors();
        }
        $this->post('/login', ['usuario' => 'juan', 'password' => 'correcta123'])->assertStatus(429);
    }

    public function test_socio_registra_gasto_con_comprobante_pero_no_entra_a_lo_de_admin(): void
    {
        Storage::fake('local');
        $socio = User::factory()->create();

        $this->actingAs($socio)->post('/gastos', [
            'fecha' => now()->toDateString(), 'monto' => 650, 'categoria' => 'Gasolina',
            'descripcion' => 'Camioneta', 'cuenta_id' => '',
            'comprobantes' => [UploadedFile::fake()->image('ticket.jpg')],
        ])->assertSessionHasNoErrors();

        $gasto = Gasto::firstOrFail();
        $this->assertSame($socio->id, $gasto->user_id);
        $this->assertNull($gasto->cuenta_id);   // dinero propio: queda por reembolsar
        $this->assertCount(1, $gasto->comprobantes);
        Storage::disk('local')->assertExists($gasto->comprobantes[0]->ruta);
        $this->get(route('comprobantes.show', $gasto->comprobantes[0]))->assertOk();

        $this->get('/datos')->assertForbidden();
        $this->post('/datos/airbnb')->assertForbidden();
        $this->post('/ajustes/usuarios', ['name' => 'x', 'usuario' => 'x', 'password' => 'secreto123'])->assertForbidden();
        $this->get('/')->assertOk();
        $this->get('/gastos')->assertOk()->assertSee('Camioneta');
    }

    public function test_admin_registra_gasto_a_nombre_de_un_socio_y_el_socio_no_puede(): void
    {
        $admin = User::factory()->admin()->create();
        $socio = User::factory()->create();
        $otro = User::factory()->create();
        $gasto = ['fecha' => now()->toDateString(), 'monto' => 500, 'categoria' => 'Gasolina'];

        $this->actingAs($admin)->post('/gastos', $gasto + ['user_id' => $socio->id])->assertSessionHasNoErrors();
        $g = Gasto::latest('id')->first();
        $this->assertSame($socio->id, $g->user_id);
        $this->assertSame($admin->id, $g->capturado_por);
        $this->actingAs($socio)->get('/gastos?filtro=mios')->assertSee('capturó '.$admin->name);

        // Un socio que intenta registrar a nombre de otro queda a su propio nombre.
        $this->actingAs($socio)->post('/gastos', $gasto + ['user_id' => $otro->id])->assertSessionHasNoErrors();
        $this->assertSame($socio->id, Gasto::latest('id')->first()->user_id);
    }

    public function test_solo_admin_registra_pagos_de_tarjeta_y_reembolsos(): void
    {
        $socio = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $pago = ['fecha' => '2026-10-20', 'monto' => 1000, 'origen_id' => 1, 'destino_id' => 2];

        $this->actingAs($socio)->post('/transferencias', $pago)->assertForbidden();
        $this->actingAs($admin)->post('/transferencias', $pago)->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/transferencias', ['destino_id' => 1] + $pago)->assertSessionHasErrors('destino_id');
        $this->actingAs($admin)->post('/reembolsos', ['fecha' => '2026-10-21', 'monto' => 50, 'origen_id' => 1,
            'reembolso_a' => $socio->id])->assertSessionHasNoErrors();
        $this->assertSame(2, \App\Models\Transferencia::count());
    }

    public function test_rechaza_archivos_que_no_son_comprobantes(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create())->post('/gastos', [
            'fecha' => now()->toDateString(), 'monto' => 10, 'categoria' => 'Otros',
            'comprobantes' => [UploadedFile::fake()->create('virus.php', 5, 'application/x-php')],
        ])->assertSessionHasErrors('comprobantes.0');
        $this->assertSame(0, Gasto::count());
    }

    public function test_socio_solo_borra_sus_gastos_y_admin_borra_todo(): void
    {
        Storage::fake('local');
        $socio = User::factory()->create();
        $otro = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $mio = Gasto::create(['fecha' => '2026-10-01', 'monto' => 1, 'categoria' => 'Otros', 'user_id' => $socio->id]);
        $ajeno = Gasto::create(['fecha' => '2026-10-01', 'monto' => 1, 'categoria' => 'Otros', 'user_id' => $otro->id]);

        $this->actingAs($socio)->delete("/gastos/{$ajeno->id}")->assertForbidden();
        $this->actingAs($socio)->delete("/gastos/{$mio->id}")->assertRedirect();
        $this->actingAs($admin)->delete("/gastos/{$ajeno->id}")->assertRedirect();
        $this->assertSame(0, Gasto::count());
    }

    public function test_todas_las_pantallas_cargan_para_el_admin(): void
    {
        $this->seed(\Database\Seeders\DemoSeeder::class);
        $admin = User::factory()->admin()->create();
        foreach (['/', '/?periodo=12m', '/flujo', '/reservas', '/gastos', '/gastos?filtro=sin-comprobante', '/datos', '/ajustes', '/estados-de-cuenta', '/estados-de-cuenta?anio=2025'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
        $this->actingAs($admin)->get('/datos/exportar/gastos?desde=2026-01-01&hasta=2026-12-31')->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_admin_agrega_desactiva_y_borra_cuartos(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/ajustes/cuartos/nuevo', ['nombre' => 'Cuarto 6'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/ajustes/cuartos/nuevo', ['nombre' => 'Bodega'])->assertSessionHasNoErrors();
        [$seis, $bodega] = Cuarto::orderBy('id')->get()->all();
        Reserva::create(['cuarto_id' => $seis->id, 'check_in' => '2026-10-01', 'noches' => 1, 'bruto' => 100]);

        // Desactivado: ya no sale para capturar reservas, pero conserva su historial.
        $this->actingAs($admin)->post('/ajustes/cuartos', ['cuartos' => [
            $seis->id => ['nombre' => 'Cuarto 6', 'activo' => '0'],
            $bodega->id => ['nombre' => 'Bodega', 'activo' => '1'],
        ]])->assertSessionHasNoErrors();
        $this->assertFalse($seis->fresh()->activo);
        $this->actingAs($admin)->get('/reservas')->assertDontSee('<option value="'.$seis->id.'">', false);

        // Con reservas no se borra; sin historial sí.
        $this->actingAs($admin)->delete("/ajustes/cuartos/{$seis->id}")->assertSessionHasErrors('cuarto');
        $this->actingAs($admin)->delete("/ajustes/cuartos/{$bodega->id}")->assertSessionHasNoErrors();
        $this->assertSame(['Cuarto 6'], Cuarto::pluck('nombre')->all());

        $socio = User::factory()->create();
        $this->actingAs($socio)->post('/ajustes/cuartos/nuevo', ['nombre' => 'X'])->assertForbidden();
    }

    public function test_gasto_con_factura_exige_subir_la_factura(): void
    {
        $socio = User::factory()->create();
        $gasto = ['fecha' => now()->toDateString(), 'monto' => 900, 'categoria' => 'Gasolina', 'factura' => '1'];
        $this->actingAs($socio)->post('/gastos', $gasto)->assertSessionHasErrors('comprobantes');
        $this->assertSame(0, Gasto::count());

        Storage::fake('local');
        $pdf = UploadedFile::fake()->createWithContent('factura.pdf', "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $this->actingAs($socio)->post('/gastos', $gasto + ['comprobantes' => [$pdf]])->assertSessionHasNoErrors();
        $this->assertTrue(Gasto::sole()->factura);
    }

    public function test_filtra_reservas_por_cuarto_y_fechas_y_exporta_lo_mismo(): void
    {
        $admin = User::factory()->admin()->create();
        $a = Cuarto::create(['nombre' => 'Volver a Verde 1']);
        $b = Cuarto::create(['nombre' => 'Volver a Verde 2']);
        Reserva::create(['cuarto_id' => $a->id, 'huesped' => 'Ana', 'check_in' => '2026-03-10', 'noches' => 2, 'bruto' => 2000, 'comision' => 300]);
        Reserva::create(['cuarto_id' => $a->id, 'huesped' => 'Beto', 'check_in' => '2026-05-10', 'noches' => 1, 'bruto' => 1000]);
        Reserva::create(['cuarto_id' => $b->id, 'huesped' => 'Caro', 'check_in' => '2026-03-15', 'noches' => 3, 'bruto' => 3000]);

        $filtro = ['cuarto_id' => $a->id, 'desde' => '2026-03-01', 'hasta' => '2026-03-31'];
        $this->actingAs($admin)->get('/reservas?'.http_build_query($filtro))
            ->assertOk()->assertSee('Ana')->assertDontSee('Beto')->assertDontSee('Caro')
            ->assertSee(dinero(1700));   // neto de lo filtrado

        $csv = $this->actingAs($admin)->get('/datos/exportar/reservas?'.http_build_query($filtro))->streamedContent();
        $this->assertStringContainsString('Ana', $csv);
        $this->assertStringNotContainsString('Caro', $csv);
        $this->assertStringNotContainsString('Beto', $csv);
    }

    public function test_reporte_de_gastos_por_socio_con_filtros_y_csv(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Oscar']);
        $ana = User::factory()->create(['name' => 'Ana']);
        $debito = \App\Models\Cuenta::where('tipo', 'debito')->first();
        Gasto::create(['fecha' => '2026-10-05', 'monto' => 800, 'categoria' => 'Gasolina', 'descripcion' => 'gas-ana', 'user_id' => $ana->id]);
        Gasto::create(['fecha' => '2026-10-06', 'monto' => 300, 'categoria' => 'Otros', 'descripcion' => 'doctor-ana', 'personal' => true, 'user_id' => $ana->id]);
        Gasto::create(['fecha' => '2026-09-01', 'monto' => 999, 'categoria' => 'Otros', 'descripcion' => 'viejo-ana', 'user_id' => $ana->id]);
        Gasto::create(['fecha' => '2026-10-07', 'monto' => 500, 'categoria' => 'Luz', 'descripcion' => 'luz-oscar', 'user_id' => $admin->id, 'cuenta_id' => $debito->id]);

        $filtro = ['user_id' => $ana->id, 'desde' => '2026-10-01', 'hasta' => '2026-10-31'];
        $this->actingAs($admin)->get('/gastos?'.http_build_query($filtro))->assertOk()
            ->assertSee('gas-ana')->assertSee('doctor-ana')->assertDontSee('viejo-ana')->assertDontSee('luz-oscar')
            ->assertSeeInOrder(['Ana', dinero(800), dinero(300), dinero(1100), dinero(1100), dinero(2099)]);   // negocio, no negocio, propio, total, le debemos

        $csv = $this->actingAs($admin)->get('/datos/exportar/gastos?'.http_build_query($filtro))->streamedContent();
        $this->assertStringContainsString('gas-ana', $csv);
        $this->assertStringNotContainsString('viejo-ana', $csv);
        $this->assertStringNotContainsString('luz-oscar', $csv);
    }

    public function test_socio_con_tarjeta_del_negocio_le_debe_lo_personal_y_aviso_de_tope(): void
    {
        $admin = User::factory()->admin()->create();
        $ana = User::factory()->create(['name' => 'Ana', 'limite_mensual' => 1000]);
        $tarjeta = \App\Models\Cuenta::create(['nombre' => 'Crédito Ana', 'tipo' => 'credito', 'fecha_saldo' => '2026-01-01', 'user_id' => $ana->id]);
        $hoy = now()->toDateString();

        // Su tarjeta sale como opción por defecto en el formulario.
        $this->actingAs($ana)->get('/gastos')->assertSee('<option value="'.$tarjeta->id.'" selected', false);

        // Gasto del negocio: no debe nada. Gasto personal con la tarjeta del negocio: lo debe.
        $this->actingAs($ana)->post('/gastos', ['fecha' => $hoy, 'monto' => 700, 'categoria' => 'Gasolina', 'cuenta_id' => $tarjeta->id]);
        $this->actingAs($ana)->post('/gastos', ['fecha' => $hoy, 'monto' => 600, 'categoria' => 'Otros', 'personal' => '1', 'cuenta_id' => $tarjeta->id]);
        $this->actingAs($ana)->post('/gastos', ['fecha' => $hoy, 'monto' => 500, 'categoria' => 'Otros', 'personal' => '1', 'cuenta_id' => $tarjeta->id])
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'tope'));
        $f = new \App\Services\Finanzas;
        $this->assertEqualsWithDelta(1100, $f->nosDeben()[0]['monto'], 0.01);
        $this->assertSame([], $f->porReembolsar());

        // Ana repone 1,000 al débito: debe 100 y el débito sube.
        $debito = \App\Models\Cuenta::where('tipo', 'debito')->first();
        $antes = collect($f->flujo()['cuentas'])->firstWhere('id', $debito->id)['saldo'];
        $this->actingAs($admin)->post('/abonos', ['fecha' => $hoy, 'monto' => 1000, 'cuenta_id' => $debito->id, 'user_id' => $ana->id])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(100, $f->nosDeben()[0]['monto'], 0.01);
        $this->assertEqualsWithDelta($antes + 1000, collect($f->flujo()['cuentas'])->firstWhere('id', $debito->id)['saldo'], 0.01);
        $this->actingAs($admin)->get('/gastos')->assertSee('Pasó su tope')->assertSee('Le debe al negocio hoy');
        $this->actingAs($admin)->get('/flujo')->assertOk()->assertSee('Ana le debe al negocio');
        $this->actingAs($ana)->post('/abonos', ['fecha' => $hoy, 'monto' => 1, 'cuenta_id' => $debito->id, 'user_id' => $ana->id])->assertForbidden();
    }

    public function test_contrasena_de_8_y_cambio_obligatorio(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        // Menos de 8 caracteres: no se acepta.
        $this->post('/ajustes/usuarios', ['name' => 'Ana', 'usuario' => 'ana', 'password' => 'corta1'])
            ->assertSessionHasErrors('password');
        $this->post('/ajustes/usuarios', ['name' => 'Ana', 'usuario' => 'ana', 'password' => 'Temporal12345'])
            ->assertSessionHasNoErrors();

        // Con contraseña temporal solo puede ir a Ajustes hasta cambiarla.
        $ana = User::where('usuario', 'ana')->firstOrFail();
        $this->assertTrue($ana->cambiar_password);
        $this->actingAs($ana)->get('/gastos')->assertRedirect('/ajustes');
        $this->get('/ajustes')->assertOk()->assertSee('Antes de seguir');

        $this->post('/ajustes/password', ['actual' => 'Temporal12345', 'password' => 'casa1',
            'password_confirmation' => 'casa1'])->assertSessionHasErrors('password');
        // 8 letras minúsculas sin números ya se aceptan.
        $this->post('/ajustes/password', ['actual' => 'Temporal12345', 'password' => 'volververde',
            'password_confirmation' => 'volververde'])->assertSessionHasNoErrors();

        $this->assertFalse($ana->fresh()->cambiar_password);
        $this->get('/gastos')->assertOk();
    }

    public function test_socios_suben_estados_de_cuenta_de_sus_tarjetas(): void
    {
        Storage::fake('local');
        $ana = User::factory()->create(['name' => 'Ana']);
        $beto = User::factory()->create();
        $suya = \App\Models\Cuenta::create(['nombre' => 'Visa Ana', 'tipo' => 'credito', 'saldo_inicial' => 0,
            'fecha_saldo' => '2026-01-01', 'user_id' => $ana->id]);
        $deBeto = \App\Models\Cuenta::create(['nombre' => 'Amex Beto', 'tipo' => 'credito', 'saldo_inicial' => 0,
            'fecha_saldo' => '2026-01-01', 'user_id' => $beto->id]);

        $pdf = fn () => UploadedFile::fake()->create('octubre.pdf', 200, 'application/pdf');
        $this->actingAs($ana)->post('/estados-de-cuenta', ['cuenta_id' => $suya->id, 'mes' => '2026-10', 'archivos' => [$pdf()]])
            ->assertSessionHasNoErrors()->assertRedirect('/estados-de-cuenta?anio=2026');
        // No puede subir a la tarjeta de otro socio.
        $this->post('/estados-de-cuenta', ['cuenta_id' => $deBeto->id, 'mes' => '2026-10', 'archivos' => [$pdf()]])->assertForbidden();
        // Hace falta el archivo.
        $this->post('/estados-de-cuenta', ['cuenta_id' => $suya->id, 'mes' => '2026-10'])->assertSessionHasErrors('archivos');

        $estado = \App\Models\EstadoCuenta::sole();
        Storage::disk('local')->assertExists($estado->ruta);
        $this->get('/estados-de-cuenta?anio=2026')->assertOk()->assertSee('Visa Ana')->assertSee(route('estados.show', $estado));
        $this->get(route('estados.show', $estado))->assertOk();

        // Otro socio lo ve pero no lo borra; quien lo subió, sí.
        $this->actingAs($beto)->delete(route('estados.destroy', $estado))->assertForbidden();
        $this->actingAs($ana)->delete(route('estados.destroy', $estado))->assertRedirect();
        Storage::disk('local')->assertMissing($estado->ruta);
        $this->assertSame(0, \App\Models\EstadoCuenta::count());
    }

    public function test_solo_admin_edita_gastos(): void
    {
        $admin = User::factory()->admin()->create();
        $socio = User::factory()->create();
        $gasto = Gasto::create(['fecha' => '2026-11-04', 'monto' => 1253, 'categoria' => 'Limpieza', 'descripcion' => 'Impuestos',
            'personal' => false, 'factura' => false, 'cuenta_id' => 1, 'user_id' => $socio->id, 'capturado_por' => $socio->id]);
        $cambios = ['fecha' => '2026-11-04', 'monto' => '1253.50', 'categoria' => 'Impuestos', 'descripcion' => 'Pago SAT',
            'user_id' => $socio->id, 'cuenta_id' => 1];

        $this->actingAs($socio)->get(route('gastos.edit', $gasto))->assertForbidden();
        $this->actingAs($socio)->put(route('gastos.update', $gasto), $cambios)->assertForbidden();
        $this->actingAs($socio)->get('/gastos')->assertDontSee(route('gastos.edit', $gasto));

        $this->actingAs($admin)->get('/gastos')->assertSee(route('gastos.edit', $gasto));
        $this->actingAs($admin)->get(route('gastos.edit', $gasto))->assertOk()->assertSee('1253.00');
        $this->actingAs($admin)->put(route('gastos.update', $gasto), $cambios + ['volver' => 'https://otro.sitio/x'])
            ->assertRedirect(route('gastos.index'))->assertSessionHasNoErrors();

        $gasto->refresh();
        $this->assertSame('Impuestos', $gasto->categoria);
        $this->assertSame(1253.5, $gasto->monto);
        $this->assertSame('Pago SAT', $gasto->descripcion);
        $this->assertSame($socio->id, $gasto->capturado_por);   // no cambia quién lo capturó

        // Con factura hay que subirla.
        $this->actingAs($admin)->put(route('gastos.update', $gasto), $cambios + ['factura' => 1])->assertSessionHasErrors('comprobantes');
    }
}
