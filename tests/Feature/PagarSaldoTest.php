<?php

namespace Tests\Feature;

use App\Enums\EstadoPaquete;
use App\Enums\EstadoPermiso;
use App\Enums\EstadoReserva;
use App\Enums\EstadoSaldo;
use App\Enums\Rol;
use App\Enums\TipoPago;
use App\Models\Excursion;
use App\Models\Guia;
use App\Models\Pago;
use App\Models\Paquete;
use App\Models\Recorrido;
use App\Models\Reserva;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\MercadoPagoDePrueba;
use Tests\TestCase;

// La pantalla de Pagar Saldo Pendiente (CU-16, pasos 1 a 6 y cursos A1 a A4), con la reserva 000125-9 de los seeders:
// Confirmada, total de USD 1.500, la seña de 750 pagada y el saldo que vence el 01/01/2027.
class PagarSaldoTest extends TestCase
{
    use RefreshDatabase;

    private const DIRECCION_MERCADO_PAGO = 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=123-abc';

    private Excursion $excursion;

    private Reserva $reserva;

    private MercadoPagoDePrueba $mercadoPago;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-13 10:00:00'));

        config(['services.mercadopago.access_token' => 'TOKEN-DE-PRUEBA']);
        // Un plazo fijo para la prueba, así no depende de un cambio en la configuración.
        config(['reserva.minutos_vigencia_pago_saldo' => 30]);

        $this->reserva = $this->crearReservaConfirmada();
        $this->mercadoPago = MercadoPagoDePrueba::instalar();
    }

    protected function tearDown(): void
    {
        MercadoPagoDePrueba::desinstalar();

        parent::tearDown();
    }

    public function test_sin_acceso_en_la_sesion_vuelve_a_mi_reserva(): void
    {
        $this->get('/mi-reserva/000125-9/pagar-saldo')->assertRedirect('/mi-reserva');

        $this->assertSame([], $this->mercadoPago->pedidos);
    }

    public function test_con_el_acceso_de_otra_reserva_vuelve_a_mi_reserva(): void
    {
        $this->crearOtraReserva('000122-3', 'valentina.diaz@mail.com');
        $this->darAcceso('valentina.diaz@mail.com', '000122-3');

        $this->get('/mi-reserva/000125-9/pagar-saldo')->assertRedirect('/mi-reserva');
    }

    public function test_con_acceso_muestra_el_saldo_y_la_fecha_limite(): void
    {
        $this->darAcceso();

        $this->get('/mi-reserva/000125-9/pagar-saldo')
            ->assertOk()
            ->assertSee('Pago del saldo')
            ->assertSeeInOrder(['Número de reserva', '000125-9'])
            ->assertSee('Camino Inca Clásico')
            ->assertSeeInOrder(['Salida', '01/02/2027'])
            ->assertSeeInOrder(['Monto total de la reserva', 'USD 1.500,00'])
            ->assertSeeInOrder(['Abonado (seña, 01/10/2026)', 'USD 750,00'])
            ->assertSeeInOrder(['Fecha límite para pagar el saldo', '01/01/2027'])
            ->assertSeeInOrder(['Saldo pendiente', 'USD 750,00'])
            ->assertSee('Mercado Pago te cobra el equivalente en pesos, con su cotización del momento.')
            ->assertSee('Pagar el saldo con Mercado Pago')
            ->assertSee('Cancelar')
            ->assertDontSee('El pago fue rechazado');
    }

    public function test_el_correo_de_la_sesion_puede_tener_otras_mayusculas(): void
    {
        $this->darAcceso('Federico.Rios@Mail.com');

        $this->get('/mi-reserva/000125-9/pagar-saldo')
            ->assertOk()
            ->assertSeeInOrder(['Saldo pendiente', 'USD 750,00']);
    }

    public function test_con_el_saldo_abonado_avisa_que_no_tiene_saldo_pendiente(): void
    {
        $this->reserva->update(['estado_saldo' => EstadoSaldo::Abonado]);
        $this->darAcceso();

        $this->abrirPantalla()
            ->assertSee('No se puede pagar el saldo')
            ->assertSee('Esta reserva no tiene saldo pendiente.')
            ->assertSeeHtml('href="/mi-reserva"')
            ->assertDontSee('Pagar el saldo con Mercado Pago')
            ->assertDontSee('Saldo pendiente');
    }

    public function test_una_reserva_pendiente_avisa_que_no_tiene_saldo_pendiente(): void
    {
        $this->reserva->update(['estado' => EstadoReserva::Pendiente]);
        $this->darAcceso();

        $this->abrirPantalla()
            ->assertSee('Esta reserva no tiene saldo pendiente.')
            ->assertDontSee('Pagar el saldo con Mercado Pago');
    }

    public function test_con_el_total_pagado_avisa_que_no_tiene_saldo_pendiente_y_no_el_vencimiento(): void
    {
        // Como 000122-3 de los seeders: pagó el total y no tiene fecha límite de saldo.
        $this->reserva->update(['estado_saldo' => EstadoSaldo::Abonado, 'fecha_limite_saldo' => null]);
        $this->darAcceso();

        $this->abrirPantalla()
            ->assertSee('Esta reserva no tiene saldo pendiente.')
            ->assertDontSee('venció')
            ->assertDontSee('Pagar el saldo con Mercado Pago');
    }

    public function test_con_la_fecha_limite_vencida_avisa_el_vencimiento(): void
    {
        $this->travelTo(Carbon::parse('2027-01-02 10:00:00'));
        $this->darAcceso();

        $this->abrirPantalla()
            ->assertSee('El plazo para pagar el saldo venció el 01/01/2027 y la reserva se cancela por falta de pago.')
            ->assertSeeHtml('href="/mi-reserva"')
            ->assertDontSee('Pagar el saldo con Mercado Pago');

        // La cancelación por falta de pago es de CU-22: esta pantalla sólo informa.
        $this->assertSame(EstadoReserva::Confirmada, $this->reserva->fresh()->estado);
    }

    public function test_confirmar_el_pago_deriva_a_mercado_pago_con_el_saldo(): void
    {
        $this->darAcceso();
        $this->mercadoPago->responder(['id' => '123-abc', 'init_point' => self::DIRECCION_MERCADO_PAGO]);

        $this->abrirPantalla()
            ->call('confirmarPago')
            ->assertRedirect(self::DIRECCION_MERCADO_PAGO);

        $datos = $this->mercadoPago->datosDelPedido();
        $this->assertSame('Camino Inca Clásico, salida 01-02-2027 (saldo de la reserva 000125-9)', $datos['items'][0]['title']);
        $this->assertEquals(750, $datos['items'][0]['unit_price']);
        $this->assertSame('USD', $datos['items'][0]['currency_id']);
        $this->assertSame('000125-9_Saldo', $datos['external_reference']);
        $this->assertSame([
            'success' => url('/mi-reserva/000125-9/saldo-pagado'),
            'failure' => url('/mi-reserva/000125-9/saldo-pagado'),
            'pending' => url('/mi-reserva/000125-9/saldo-pagado'),
        ], $datos['back_urls']);
        // Vence a los 30 minutos.
        $this->assertTrue($datos['expires']);
        $this->assertSame('2026-10-13T10:30:00.000-03:00', $datos['expiration_date_to']);

        // Derivar no registra nada: el pago se registra a la vuelta, si Mercado Pago lo aprueba.
        $this->assertSame(1, Pago::count());
        $this->assertSame(EstadoSaldo::Adeudado, $this->reserva->fresh()->estado_saldo);
    }

    public function test_con_la_fecha_limite_mas_cerca_el_pedido_vence_en_ella(): void
    {
        $this->travelTo(Carbon::parse('2027-01-01 23:45:00'));
        $this->darAcceso();
        $this->mercadoPago->responder(['id' => '123-abc', 'init_point' => self::DIRECCION_MERCADO_PAGO]);

        $this->abrirPantalla()
            ->call('confirmarPago')
            ->assertRedirect(self::DIRECCION_MERCADO_PAGO);

        $this->assertSame('2027-01-01T23:59:59.000-03:00', $this->mercadoPago->datosDelPedido()['expiration_date_to']);
    }

    public function test_la_vigencia_del_pedido_sale_de_la_configuracion(): void
    {
        config(['reserva.minutos_vigencia_pago_saldo' => 10]);
        $this->darAcceso();
        $this->mercadoPago->responder(['id' => '123-abc', 'init_point' => self::DIRECCION_MERCADO_PAGO]);

        $this->abrirPantalla()->call('confirmarPago');

        $this->assertSame('2026-10-13T10:10:00.000-03:00', $this->mercadoPago->datosDelPedido()['expiration_date_to']);
    }

    public function test_si_el_saldo_se_pago_con_la_pantalla_abierta_no_deriva_a_mercado_pago(): void
    {
        $this->darAcceso();
        $pantalla = $this->abrirPantalla()->assertSee('Pagar el saldo con Mercado Pago');

        // En otra pestaña el cliente ya pagó el saldo.
        $this->reserva->update(['estado_saldo' => EstadoSaldo::Abonado]);

        $pantalla->call('confirmarPago')
            ->assertNoRedirect()
            ->assertSee('Esta reserva no tiene saldo pendiente.')
            ->assertDontSee('Pagar el saldo con Mercado Pago');

        $this->assertSame([], $this->mercadoPago->pedidos);
    }

    public function test_si_vence_el_plazo_con_la_pantalla_abierta_no_deriva_a_mercado_pago(): void
    {
        $this->travelTo(Carbon::parse('2027-01-01 23:59:00'));
        $this->darAcceso();
        $pantalla = $this->abrirPantalla();

        $this->travel(2)->minutes();

        $pantalla->call('confirmarPago')
            ->assertNoRedirect()
            ->assertSee('El plazo para pagar el saldo venció el 01/01/2027');

        $this->assertSame([], $this->mercadoPago->pedidos);
    }

    public function test_si_perdio_el_acceso_con_la_pantalla_abierta_no_deriva_a_mercado_pago(): void
    {
        $this->darAcceso();
        $pantalla = $this->abrirPantalla();

        session()->forget('acceso_reserva');

        $pantalla->call('confirmarPago')->assertRedirect('/mi-reserva');

        $this->assertSame([], $this->mercadoPago->pedidos);
    }

    public function test_si_mercado_pago_falla_avisa_sin_derivar(): void
    {
        $this->darAcceso();
        $this->mercadoPago->responder(['message' => 'invalid expiration_date_to', 'status' => 400], 400);

        $this->abrirPantalla()
            ->call('confirmarPago')
            ->assertNoRedirect()
            ->assertSee('No pudimos conectar con Mercado Pago')
            ->assertSee('El saldo sigue pendiente. Probá de nuevo en unos instantes.')
            ->assertSee('Pagar el saldo con Mercado Pago');

        $this->assertSame(EstadoSaldo::Adeudado, $this->reserva->fresh()->estado_saldo);
    }

    public function test_si_mercado_pago_no_responde_avisa_y_se_puede_reintentar(): void
    {
        $this->darAcceso();
        $this->mercadoPago
            ->noResponder()
            ->responder(['id' => '123-abc', 'init_point' => self::DIRECCION_MERCADO_PAGO]);

        $this->abrirPantalla()
            ->call('confirmarPago')
            ->assertNoRedirect()
            ->assertSee('No pudimos conectar con Mercado Pago')
            ->call('confirmarPago')
            ->assertRedirect(self::DIRECCION_MERCADO_PAGO);
    }

    public function test_muestra_el_aviso_de_pago_rechazado(): void
    {
        $this->darAcceso();
        session()->flash('pago_saldo_rechazado', true);

        $this->abrirPantalla()
            ->assertSee('El pago fue rechazado')
            ->assertSee('Mercado Pago rechazó el pago y el saldo sigue pendiente. Podés intentarlo de nuevo.')
            ->assertSeeInOrder(['Saldo pendiente', 'USD 750,00'])
            ->assertSee('Pagar el saldo con Mercado Pago');
    }

    public function test_el_aviso_de_rechazo_del_pago_de_la_reserva_no_se_muestra_aca(): void
    {
        $this->darAcceso();
        // El de CU-15, el de la pantalla de pago de una reserva nueva.
        session()->flash('pago_rechazado', true);

        $this->abrirPantalla()->assertDontSee('El pago fue rechazado');
    }

    public function test_cancelar_vuelve_a_mi_reserva_sin_tocar_el_saldo(): void
    {
        $this->darAcceso();

        $this->abrirPantalla()
            ->call('cancelar')
            ->assertRedirect('/mi-reserva');

        $this->assertSame([], $this->mercadoPago->pedidos);
        $this->assertSame(1, Pago::count());
        $this->assertSame(EstadoSaldo::Adeudado, $this->reserva->fresh()->estado_saldo);
    }

    private function abrirPantalla(string $numeroReserva = '000125-9')
    {
        return Livewire::test('pagar-saldo', ['numeroReserva' => $numeroReserva]);
    }

    // Como queda la sesión cuando el cliente consulta su reserva en Mi reserva.
    private function darAcceso(string $correo = 'federico.rios@mail.com', string $numeroReserva = '000125-9'): void
    {
        session(['acceso_reserva' => ['correo' => $correo, 'numeroReserva' => $numeroReserva]]);
    }

    // La reserva 000125-9 de los seeders.
    private function crearReservaConfirmada(): Reserva
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

        $this->excursion = Excursion::create([
            'id_paquete' => $paquete->id_paquete,
            'id_guia' => $usuario->id_usuario,
            'fecha_salida' => '2027-02-01',
            'cupo' => 12,
            'plazas_retenidas' => 0,
        ]);

        $reserva = Reserva::create([
            'id_excursion' => $this->excursion->id_excursion,
            'numero_reserva' => '000125-9',
            'correo_electronico' => 'federico.rios@mail.com',
            'fecha_reserva' => '2026-10-01 18:20:00',
            'estado' => EstadoReserva::Confirmada,
            'estado_saldo' => EstadoSaldo::Adeudado,
            'noches_extra_antes' => 0,
            'noches_extra_despues' => 0,
            'fecha_limite_saldo' => '2027-01-01 23:59:59',
            'fecha_limite_confirmacion' => '2027-01-01 23:59:59',
        ]);

        foreach ([['Federico', 'AAH715300'], ['Lorena', 'AAH715301']] as [$nombre, $documento]) {
            $reserva->excursionistas()->create([
                'nombre' => $nombre,
                'apellido' => 'Ríos',
                'documento_pasaporte' => $documento,
                'equipo_camping' => false,
                'estado_permiso' => EstadoPermiso::Obtenido,
            ]);
        }

        Pago::registrarPago($reserva, 750, '2026-10-01 18:20:00', 'Tarjeta de crédito', TipoPago::Sena);

        return $reserva;
    }

    // Otra reserva confirmada con el saldo adeudado, de otro cliente, en la misma salida: un integrante y la seña pagada,
    // así tiene saldo de verdad (USD 375).
    private function crearOtraReserva(string $numeroReserva, string $correo): Reserva
    {
        $reserva = Reserva::create([
            'id_excursion' => $this->excursion->id_excursion,
            'numero_reserva' => $numeroReserva,
            'correo_electronico' => $correo,
            'fecha_reserva' => '2026-09-10 16:45:00',
            'estado' => EstadoReserva::Confirmada,
            'estado_saldo' => EstadoSaldo::Adeudado,
            'noches_extra_antes' => 0,
            'noches_extra_despues' => 0,
            'fecha_limite_saldo' => '2027-01-01 23:59:59',
            'fecha_limite_confirmacion' => '2027-01-01 23:59:59',
        ]);

        $reserva->excursionistas()->create([
            'nombre' => 'Valentina',
            'apellido' => 'Díaz',
            'documento_pasaporte' => 'AAE402215',
            'equipo_camping' => false,
            'estado_permiso' => EstadoPermiso::Obtenido,
        ]);

        Pago::registrarPago($reserva, 375, '2026-09-10 16:45:00', 'Tarjeta de débito', TipoPago::Sena);

        return $reserva;
    }
}
