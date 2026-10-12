<?php

namespace Tests\Feature;

use App\Enums\EstadoPaquete;
use App\Enums\Rol;
use App\Enums\TipoPago;
use App\Jobs\LiberarCupoRetenido;
use App\Models\Excursion;
use App\Models\Guia;
use App\Models\Paquete;
use App\Models\Recorrido;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\MercadoPagoDePrueba;
use Tests\TestCase;

class PagarReservaTest extends TestCase
{
    use RefreshDatabase;

    private const DIRECCION_MERCADO_PAGO = 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=123-abc';

    private Excursion $excursion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-06 10:00:00'));

        // Un plazo fijo para la prueba, así no depende de RESERVA_MINUTOS_RETENCION en el .env de cada uno.
        config(['reserva.minutos_retencion' => 3]);
        config(['services.mercadopago.access_token' => 'TOKEN-DE-PRUEBA']);

        // 5 plazas retenidas: 2 de la reserva en curso del cliente y 3 de otros. Así se nota si se descuenta de más.
        $this->excursion = $this->crearExcursion(plazasRetenidas: 5);
    }

    // Las pruebas que derivan a Mercado Pago ponen el cliente de mentira: acá se deja otra vez el verdadero.
    protected function tearDown(): void
    {
        MercadoPagoDePrueba::desinstalar();

        parent::tearDown();
    }

    public function test_sin_reserva_en_curso_avisa_sin_error(): void
    {
        Livewire::test('pagar-reserva')
            ->assertSee('No tenés una reserva en curso.')
            ->assertSee('Ver los paquetes')
            ->assertDontSee('Pagar con Mercado Pago');
    }

    public function test_con_la_retencion_vigente_muestra_la_reserva_y_el_contador(): void
    {
        $this->guardarReservaEnCurso();

        Livewire::test('pagar-reserva')
            ->assertSee('Pago de la reserva')
            ->assertSee('Tus lugares están guardados')
            ->assertSee('Tenés 3 minutos para completar el pago. Si el plazo se vence, los lugares vuelven a estar disponibles.')
            ->assertSeeInOrder(['Retención:', '03:00'])
            ->assertSeeHtml('role="timer"')
            ->assertSee('Camino Inca Clásico')
            ->assertSee('18/01/2027')
            ->assertSee('Ana Pérez')
            ->assertSee('Bruno Gómez')
            ->assertSeeInOrder(['Llevan equipo de camping', '1 de 2'])
            ->assertSee('1 antes del recorrido')
            ->assertSeeHtmlInOrder(['disabled="disabled"', 'Pagar con Mercado Pago'])
            ->assertDontSee('Disponible próximamente');
    }

    public function test_muestra_el_total_y_el_monto_de_cada_opcion(): void
    {
        $this->guardarReservaEnCurso();

        // 2 × 750 + 1 noche × 2 × 60 + 1 equipo × 45 = 1.665; la seña es la mitad.
        Livewire::test('pagar-reserva')
            ->assertSeeInOrder(['Monto total de la reserva', 'USD 1.665,00'])
            ->assertSeeInOrder(['Pagar el total', 'USD 1.665,00', 'No queda saldo pendiente.'])
            ->assertSeeInOrder(['Pagar la seña (50 %)', 'USD 832,50', 'Queda un saldo de USD 832,50.'])
            ->assertDontSee('Vas a pagar');
    }

    public function test_elegir_la_sena_muestra_el_monto_a_pagar_y_habilita_el_pago(): void
    {
        $this->guardarReservaEnCurso();

        Livewire::test('pagar-reserva')
            ->call('realizarPago', TipoPago::Sena->value)
            ->assertSet('tipoPago', TipoPago::Sena)
            ->assertSeeInOrder(['Vas a pagar', 'USD 832,50'])
            ->assertSee('Mercado Pago te cobra el equivalente en pesos, con su cotización del momento.')
            ->assertDontSeeHtml('disabled="disabled"');
    }

    public function test_elegir_el_total_muestra_el_monto_a_pagar(): void
    {
        $this->guardarReservaEnCurso();

        Livewire::test('pagar-reserva')
            ->call('realizarPago', TipoPago::Total->value)
            ->assertSet('tipoPago', TipoPago::Total)
            ->assertSeeInOrder(['Vas a pagar', 'USD 1.665,00']);
    }

    public function test_el_saldo_u_otro_texto_no_se_eligen(): void
    {
        $this->guardarReservaEnCurso();

        Livewire::test('pagar-reserva')
            ->call('realizarPago', TipoPago::Saldo->value)
            ->assertSet('tipoPago', null)
            ->call('realizarPago', 'Gratis')
            ->assertSet('tipoPago', null)
            ->assertDontSee('Vas a pagar')
            ->assertSeeHtmlInOrder(['disabled="disabled"', 'Pagar con Mercado Pago']);
    }

    public function test_confirmar_el_pago_deriva_a_mercado_pago_con_el_monto_y_la_referencia(): void
    {
        $enCurso = $this->guardarReservaEnCurso();
        $mercadoPago = MercadoPagoDePrueba::instalar()->responder(['id' => '123-abc', 'init_point' => self::DIRECCION_MERCADO_PAGO]);

        Livewire::test('pagar-reserva')
            ->call('realizarPago', TipoPago::Sena->value)
            ->call('confirmarPago')
            ->assertRedirect(self::DIRECCION_MERCADO_PAGO);

        $datos = $mercadoPago->datosDelPedido();
        $this->assertSame('Camino Inca Clásico, salida 18-01-2027 (seña)', $datos['items'][0]['title']);
        $this->assertSame(832.5, $datos['items'][0]['unit_price']);
        $this->assertSame('USD', $datos['items'][0]['currency_id']);
        $this->assertSame($enCurso['id_retencion'].'_Sena', $datos['external_reference']);
        $this->assertSame(url('/reservar/confirmada'), $datos['back_urls']['success']);
        // Vence con la retención: a los 3 minutos.
        $this->assertSame('2026-10-06T10:03:00.000-03:00', $datos['expiration_date_to']);
    }

    public function test_confirmar_el_total_pide_el_monto_completo(): void
    {
        $enCurso = $this->guardarReservaEnCurso();
        $mercadoPago = MercadoPagoDePrueba::instalar()->responder(['id' => '123-abc', 'init_point' => self::DIRECCION_MERCADO_PAGO]);

        Livewire::test('pagar-reserva')
            ->call('realizarPago', TipoPago::Total->value)
            ->call('confirmarPago')
            ->assertRedirect(self::DIRECCION_MERCADO_PAGO);

        $datos = $mercadoPago->datosDelPedido();
        $this->assertEquals(1665, $datos['items'][0]['unit_price']);
        $this->assertSame($enCurso['id_retencion'].'_Total', $datos['external_reference']);
    }

    public function test_sin_elegir_como_pagar_no_se_deriva_a_mercado_pago(): void
    {
        $this->guardarReservaEnCurso();
        $mercadoPago = MercadoPagoDePrueba::instalar();

        Livewire::test('pagar-reserva')
            ->call('confirmarPago')
            ->assertNoRedirect();

        $this->assertSame([], $mercadoPago->pedidos);
    }

    public function test_con_la_retencion_vencida_no_se_deriva_a_mercado_pago(): void
    {
        $this->guardarReservaEnCurso();
        $mercadoPago = MercadoPagoDePrueba::instalar();
        $componente = Livewire::test('pagar-reserva')->call('realizarPago', TipoPago::Total->value);

        $this->travel(3)->minutes();

        $componente->call('confirmarPago')
            ->assertNoRedirect()
            ->assertSee('Se venció el plazo');

        $this->assertSame([], $mercadoPago->pedidos);
        $this->assertSame(3, $this->excursion->fresh()->plazas_retenidas);
    }

    public function test_una_pantalla_abierta_para_otra_reserva_no_deriva_a_mercado_pago(): void
    {
        $this->guardarReservaEnCurso();
        $mercadoPago = MercadoPagoDePrueba::instalar();
        $pantallaDeA = Livewire::test('pagar-reserva')->call('realizarPago', TipoPago::Total->value);

        // En otra pestaña el cliente confirmó otra reserva.
        $reservaB = $this->guardarReservaEnCurso();

        $pantallaDeA->call('confirmarPago')
            ->assertNoRedirect()
            ->assertSee('Esta reserva ya no está en curso.');

        $this->assertSame([], $mercadoPago->pedidos);
        $this->assertSame($reservaB, session('reserva_en_curso'));
    }

    public function test_elegir_como_pagar_en_una_pantalla_que_ya_no_esta_en_curso_solo_avisa(): void
    {
        $this->guardarReservaEnCurso();
        $componente = Livewire::test('pagar-reserva');

        // En otra pestaña el cliente pagó o canceló esta reserva: la sesión ya no la tiene.
        session()->forget('reserva_en_curso');

        $componente->call('realizarPago', TipoPago::Total->value)
            ->assertSet('tipoPago', null)
            ->assertSee('Esta reserva ya no está en curso.');
    }

    public function test_varias_acciones_en_un_mismo_pedido_no_mezclan_el_monto_con_el_tipo_de_pago(): void
    {
        $enCurso = $this->guardarReservaEnCurso();
        $mercadoPago = MercadoPagoDePrueba::instalar()
            ->responder(['id' => '123-abc', 'init_point' => self::DIRECCION_MERCADO_PAGO])
            ->responder(['id' => '456-def', 'init_point' => self::DIRECCION_MERCADO_PAGO]);

        // El navegador puede mandar varias acciones juntas en un mismo pedido. Livewire guarda los #[Computed] durante todo
        // el pedido: el segundo cobro no puede salir con el monto de la seña y la referencia del total.
        $llamadas = array_map(fn ($llamada) => ['method' => $llamada[0], 'params' => array_slice($llamada, 1), 'path' => ''], [
            ['realizarPago', TipoPago::Sena->value],
            ['confirmarPago'],
            ['realizarPago', TipoPago::Total->value],
            ['confirmarPago'],
        ]);

        Livewire::test('pagar-reserva')->update(calls: $llamadas);

        $this->assertCount(2, $mercadoPago->pedidos);
        $this->assertSame($enCurso['id_retencion'].'_Sena', $mercadoPago->datosDelPedido(0)['external_reference']);
        $this->assertEquals(832.5, $mercadoPago->datosDelPedido(0)['items'][0]['unit_price']);
        $this->assertSame($enCurso['id_retencion'].'_Total', $mercadoPago->datosDelPedido(1)['external_reference']);
        $this->assertEquals(1665, $mercadoPago->datosDelPedido(1)['items'][0]['unit_price']);
    }

    public function test_si_mercado_pago_falla_avisa_y_los_lugares_siguen_guardados(): void
    {
        $enCurso = $this->guardarReservaEnCurso();
        MercadoPagoDePrueba::instalar()->responder(['message' => 'invalid expiration_date_to', 'status' => 400], 400);

        Livewire::test('pagar-reserva')
            ->call('realizarPago', TipoPago::Sena->value)
            ->call('confirmarPago')
            ->assertNoRedirect()
            ->assertSee('No pudimos conectar con Mercado Pago')
            ->assertSee('Tus lugares siguen guardados. Probá de nuevo en unos instantes.');

        $this->assertSame(5, $this->excursion->fresh()->plazas_retenidas);
        $this->assertSame($enCurso, session('reserva_en_curso'));
    }

    public function test_si_mercado_pago_no_responde_avisa_sin_derivar(): void
    {
        $this->guardarReservaEnCurso();
        MercadoPagoDePrueba::instalar()->noResponder();

        Livewire::test('pagar-reserva')
            ->call('realizarPago', TipoPago::Total->value)
            ->call('confirmarPago')
            ->assertNoRedirect()
            ->assertSee('No pudimos conectar con Mercado Pago');
    }

    public function test_muestra_el_aviso_de_pago_rechazado(): void
    {
        $this->guardarReservaEnCurso();
        session()->flash('pago_rechazado', true);

        Livewire::test('pagar-reserva')
            ->assertSee('El pago fue rechazado')
            ->assertSee('Mercado Pago rechazó el pago y no se generó la reserva. Podés intentarlo de nuevo mientras tus lugares sigan guardados.')
            ->assertSee('Tus lugares están guardados');
    }

    public function test_sin_rechazo_no_muestra_el_aviso(): void
    {
        $this->guardarReservaEnCurso();

        Livewire::test('pagar-reserva')->assertDontSee('El pago fue rechazado');
    }

    public function test_cancelar_libera_los_lugares_una_sola_vez(): void
    {
        $enCurso = $this->guardarReservaEnCurso();

        Livewire::test('pagar-reserva')
            ->call('cancelar')
            ->assertRedirect('/');

        $this->assertSame(3, $this->excursion->fresh()->plazas_retenidas);
        $this->assertNull(session('reserva_en_curso'));

        // Después llega la tarea demorada del vencimiento: no descuenta de nuevo.
        (new LiberarCupoRetenido($this->excursion->id_excursion, 2, $enCurso['id_retencion']))->handle();

        $this->assertSame(3, $this->excursion->fresh()->plazas_retenidas);
    }

    public function test_si_vencio_al_abrir_la_pantalla_libera_los_lugares(): void
    {
        $this->guardarReservaEnCurso();
        $this->travel(4)->minutes();

        Livewire::test('pagar-reserva')
            ->assertSee('Se venció el plazo')
            ->assertSee('Pasaron los 3 minutos sin que se registrara el pago y tus lugares se liberaron. Podés volver a reservar.')
            ->assertSee('Volver a reservar')
            ->assertSeeHtml('href="/reservar/'.$this->excursion->id_paquete.'/2027-01-18"')
            ->assertDontSee('Pagar con Mercado Pago');

        $this->assertSame(3, $this->excursion->fresh()->plazas_retenidas);
        $this->assertNull(session('reserva_en_curso'));
    }

    public function test_si_la_tarea_demorada_ya_libero_los_lugares_no_descuenta_de_nuevo(): void
    {
        $enCurso = $this->guardarReservaEnCurso();
        $this->travel(4)->minutes();

        // La cola ya corrió la tarea del vencimiento antes de que el cliente vuelva a la pantalla.
        (new LiberarCupoRetenido($this->excursion->id_excursion, 2, $enCurso['id_retencion']))->handle();

        Livewire::test('pagar-reserva')->assertSee('Se venció el plazo');

        $this->assertSame(3, $this->excursion->fresh()->plazas_retenidas);
    }

    public function test_si_vence_con_la_pantalla_abierta_libera_los_lugares_al_comprobar(): void
    {
        $this->guardarReservaEnCurso();
        $componente = Livewire::test('pagar-reserva')->assertSee('Tus lugares están guardados');

        $this->travel(3)->minutes();

        $componente->call('comprobarVencimiento')
            ->assertReturned(0)
            ->assertSee('Se venció el plazo')
            ->assertSee('Volver a reservar');

        $this->assertSame(3, $this->excursion->fresh()->plazas_retenidas);
        $this->assertNull(session('reserva_en_curso'));
    }

    public function test_antes_de_vencer_comprobar_no_libera_nada(): void
    {
        $this->guardarReservaEnCurso();
        $componente = Livewire::test('pagar-reserva');

        $this->travel(170)->seconds();

        // Al navegador le contesta los segundos que faltan, para que el contador siga desde ahí.
        $componente->call('comprobarVencimiento')
            ->assertReturned(10)
            ->assertSee('Tus lugares están guardados');

        $this->assertSame(5, $this->excursion->fresh()->plazas_retenidas);
        $this->assertNotNull(session('reserva_en_curso'));
    }

    public function test_una_pantalla_abierta_para_otra_reserva_no_libera_la_que_esta_en_curso_al_cancelar(): void
    {
        $this->guardarReservaEnCurso();
        $pantallaDeA = Livewire::test('pagar-reserva')->assertSee('Tus lugares están guardados');

        // En otra pestaña el cliente confirmó otra reserva: la sesión ahora tiene B, con las 2 plazas del cliente.
        $reservaB = $this->guardarReservaEnCurso();

        $pantallaDeA->call('cancelar')
            ->assertNoRedirect()
            ->assertSee('Esta reserva ya no está en curso.')
            ->assertDontSee('No tenés una reserva en curso.');

        $this->assertSame(5, $this->excursion->fresh()->plazas_retenidas);
        $this->assertSame($reservaB, session('reserva_en_curso'));
    }

    public function test_una_pantalla_abierta_para_otra_reserva_no_libera_la_que_esta_en_curso_al_comprobar(): void
    {
        $this->guardarReservaEnCurso();
        $pantallaDeA = Livewire::test('pagar-reserva');

        $reservaB = $this->guardarReservaEnCurso();

        // Ni siquiera con el plazo pasado: B no es la reserva de esta pantalla.
        $this->travel(4)->minutes();

        $pantallaDeA->call('comprobarVencimiento')
            ->assertReturned(0)
            ->assertSee('Esta reserva ya no está en curso.')
            ->assertDontSee('Se venció el plazo');

        $this->assertSame(5, $this->excursion->fresh()->plazas_retenidas);
        $this->assertSame($reservaB, session('reserva_en_curso'));
    }

    public function test_si_la_reserva_se_cancelo_en_otra_pestana_avisa_que_ya_no_esta_en_curso(): void
    {
        $this->guardarReservaEnCurso();
        $componente = Livewire::test('pagar-reserva');

        // En otra pestaña el cliente canceló esta misma reserva.
        session()->forget('reserva_en_curso');

        $componente->call('comprobarVencimiento')
            ->assertSee('Esta reserva ya no está en curso.')
            ->assertDontSee('No tenés una reserva en curso.');
    }

    private function guardarReservaEnCurso(): array
    {
        $enCurso = [
            'id_retencion' => (string) Str::uuid(),
            'id_excursion' => $this->excursion->id_excursion,
            'correo_electronico' => 'ana.perez@mail.com',
            'noches_extra_antes' => 1,
            'noches_extra_despues' => 0,
            'integrantes' => [
                ['nombre' => 'Ana', 'apellido' => 'Pérez', 'documento_pasaporte' => 'AAA111', 'equipo_camping' => true],
                ['nombre' => 'Bruno', 'apellido' => 'Gómez', 'documento_pasaporte' => 'BBB222', 'equipo_camping' => false],
            ],
            'vence' => now()->addMinutes(config('reserva.minutos_retencion'))->toIso8601String(),
        ];

        session(['reserva_en_curso' => $enCurso]);

        return $enCurso;
    }

    private function crearExcursion(int $plazasRetenidas): Excursion
    {
        $recorrido = Recorrido::create(['nombre' => 'Camino Inca de 4 días', 'duracion_dias' => 4, 'cantidad_campings' => 3]);

        $paquete = Paquete::create([
            'id_recorrido' => $recorrido->id_recorrido,
            'nombre' => 'Camino Inca Clásico',
            'precio_base' => 750,
            'costo_noche_extra_cusco' => 60,
            'costo_equipo_camping' => 45,
            'cantidad_porteadores' => 6,
            'estado' => EstadoPaquete::Activo,
            'fecha_creacion' => '2026-01-05',
        ]);

        $usuario = Usuario::create(['correo' => 'guia@caminodelinca.test', 'password' => 'guia1234', 'rol' => Rol::Guia]);
        Guia::create(['id_usuario' => $usuario->id_usuario, 'nombre' => 'Rosa', 'apellido' => 'Quispe']);

        return Excursion::create([
            'id_paquete' => $paquete->id_paquete,
            'id_guia' => $usuario->id_usuario,
            'fecha_salida' => '2027-01-18',
            'cupo' => 12,
            'plazas_retenidas' => $plazasRetenidas,
        ]);
    }
}
