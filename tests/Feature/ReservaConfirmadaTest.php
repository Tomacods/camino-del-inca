<?php

namespace Tests\Feature;

use App\Enums\EstadoPaquete;
use App\Enums\EstadoReserva;
use App\Enums\EstadoSaldo;
use App\Enums\Rol;
use App\Enums\TipoPago;
use App\Models\Excursion;
use App\Models\Guia;
use App\Models\Paquete;
use App\Models\Recorrido;
use App\Models\Reserva;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\MercadoPagoDePrueba;
use Tests\TestCase;

// La vuelta de Mercado Pago y la pantalla de la reserva registrada (CU-15, pasos 6 a 11 y cursos A2 y A3).
class ReservaConfirmadaTest extends TestCase
{
    use RefreshDatabase;

    private const NUMERO_OPERACION = '183193192230';

    private Excursion $excursion;

    private MercadoPagoDePrueba $mercadoPago;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-06 10:00:00'));

        config(['reserva.minutos_retencion' => 3]);
        config(['services.mercadopago.access_token' => 'TOKEN-DE-PRUEBA']);

        // 5 plazas retenidas: 2 de la reserva en curso del cliente y 3 de otros. Así se nota si se descuenta de más.
        $this->excursion = $this->crearExcursion(plazasRetenidas: 5);
        $this->mercadoPago = MercadoPagoDePrueba::instalar();
    }

    protected function tearDown(): void
    {
        MercadoPagoDePrueba::desinstalar();

        parent::tearDown();
    }

    public function test_con_el_pago_aprobado_registra_la_reserva_y_vuelve_sin_parametros(): void
    {
        $enCurso = $this->guardarReservaEnCurso();
        $this->mercadoPago->responder($this->pago('approved', $enCurso['id_retencion'].'_Total'));

        $this->volverDeMercadoPago()->assertRedirect('/reservar/confirmada');

        $reserva = Reserva::sole();
        $this->assertSame(EstadoReserva::Pendiente, $reserva->estado);
        $this->assertSame(EstadoSaldo::Abonado, $reserva->estado_saldo);
        $this->assertSame(2, $reserva->excursionistas()->count());

        $pago = $reserva->pagos()->sole();
        $this->assertSame(TipoPago::Total, $pago->tipo_pago);
        $this->assertSame('1665.00', $pago->monto);
        $this->assertSame('Tarjeta de crédito', $pago->medio_pago);

        // Las plazas pasan de retenidas a reservadas.
        $this->assertSame(3, $this->excursion->fresh()->plazas_retenidas);
        $this->assertNull(session('reserva_en_curso'));
        $this->assertSame([
            'id_retencion' => $enCurso['id_retencion'],
            'numero_reserva' => $reserva->numero_reserva,
            'correo_electronico' => 'ana.perez@mail.com',
        ], session('reserva_confirmada'));
    }

    public function test_la_pantalla_muestra_el_numero_de_reserva_y_el_comprobante(): void
    {
        $enCurso = $this->guardarReservaEnCurso();
        $this->mercadoPago->responder($this->pago('approved', $enCurso['id_retencion'].'_Total'));
        $this->volverDeMercadoPago();

        $reserva = Reserva::sole();
        $comprobante = $reserva->pagos()->sole()->comprobante;

        $this->get('/reservar/confirmada')
            ->assertOk()
            ->assertSee('Reserva registrada.')
            ->assertSeeInOrder(['Tu número de reserva', $reserva->numero_reserva])
            ->assertSee('con tu correo (ana.perez@mail.com) y este número consultás tu reserva en Mi reserva', false)
            ->assertSee(EstadoReserva::Pendiente->value)
            ->assertSee('Falta validar los permisos de ingreso al Camino del Inca.')
            ->assertSeeInOrder(['Comprobante', $comprobante->numero_comprobante, '06/10/2026 10:00', TipoPago::Total->value, 'Tarjeta de crédito', 'USD 1.665,00'])
            ->assertSee('Camino Inca Clásico')
            ->assertSee('18/01/2027')
            ->assertSee('Ana Pérez')
            ->assertSee('Bruno Gómez')
            ->assertDontSee('Saldo pendiente')
            ->assertSee('href="/mi-reserva"', false);
    }

    public function test_con_la_sena_muestra_el_saldo_pendiente_y_su_fecha_limite(): void
    {
        $enCurso = $this->guardarReservaEnCurso();
        $this->mercadoPago->responder($this->pago('approved', $enCurso['id_retencion'].'_Sena', 'debit_card'));
        $this->volverDeMercadoPago();

        $pago = Reserva::sole()->pagos()->sole();
        $this->assertSame(TipoPago::Sena, $pago->tipo_pago);
        $this->assertSame('832.50', $pago->monto);
        $this->assertSame('Tarjeta de débito', $pago->medio_pago);

        // La salida es el 18/01/2027: el saldo vence un mes antes.
        $this->get('/reservar/confirmada')
            ->assertSeeInOrder([TipoPago::Sena->value, 'Tarjeta de débito', 'USD 832,50'])
            ->assertSeeInOrder(['Saldo pendiente', 'USD 832,50'])
            ->assertSeeInOrder(['Fecha límite para pagar el saldo', '18/12/2026']);
    }

    public function test_con_el_pago_rechazado_no_registra_la_reserva_y_vuelve_al_pago_con_el_aviso(): void
    {
        $enCurso = $this->guardarReservaEnCurso();
        $this->mercadoPago->responder($this->pago('rejected', $enCurso['id_retencion'].'_Total'));

        $this->volverDeMercadoPago(['status' => 'rejected'])->assertRedirect('/reservar/pago');

        $this->assertSame(0, Reserva::count());
        $this->assertSame(5, $this->excursion->fresh()->plazas_retenidas);
        $this->assertSame($enCurso, session('reserva_en_curso'));

        Livewire::test('pagar-reserva')
            ->assertSee('El pago fue rechazado')
            ->assertSee('Tus lugares están guardados');
    }

    public function test_no_se_le_cree_a_la_direccion_si_mercado_pago_dice_que_se_rechazo(): void
    {
        $enCurso = $this->guardarReservaEnCurso();
        $this->mercadoPago->responder($this->pago('rejected', $enCurso['id_retencion'].'_Total'));

        $this->volverDeMercadoPago(['status' => 'approved', 'collection_status' => 'approved'])
            ->assertRedirect('/reservar/pago');

        $this->assertSame(0, Reserva::count());
    }

    public function test_un_pago_de_otra_retencion_no_registra_la_reserva_en_curso(): void
    {
        $enCurso = $this->guardarReservaEnCurso();
        $this->mercadoPago->responder($this->pago('approved', Str::uuid().'_Total'));

        $this->volverDeMercadoPago()
            ->assertOk()
            // El número tiene que estar en el aviso: también figura en los datos internos de Livewire, al principio.
            ->assertSeeInOrder(['No pudimos registrar la reserva', 'La devolución del dinero se gestiona con la agencia', self::NUMERO_OPERACION]);

        $this->assertSame(0, Reserva::count());
        $this->assertSame(5, $this->excursion->fresh()->plazas_retenidas);
        $this->assertSame($enCurso, session('reserva_en_curso'));
    }

    public function test_con_la_retencion_vencida_no_registra_la_reserva_y_muestra_el_numero_de_operacion(): void
    {
        $enCurso = $this->guardarReservaEnCurso();
        $this->mercadoPago->responder($this->pago('approved', $enCurso['id_retencion'].'_Total'));

        // El cliente tardó en pagar: Mercado Pago aprobó, pero la retención ya venció.
        $this->travel(4)->minutes();

        $this->volverDeMercadoPago()
            ->assertOk()
            ->assertSeeInOrder(['No pudimos registrar la reserva', 'el plazo se venció o la reserva se canceló', self::NUMERO_OPERACION]);

        $this->assertSame(0, Reserva::count());
    }

    public function test_la_misma_vuelta_dos_veces_registra_una_sola_reserva(): void
    {
        $enCurso = $this->guardarReservaEnCurso();
        $this->mercadoPago
            ->responder($this->pago('approved', $enCurso['id_retencion'].'_Total'))
            ->responder($this->pago('approved', $enCurso['id_retencion'].'_Total'));

        $this->volverDeMercadoPago()->assertRedirect('/reservar/confirmada');
        $numeroReserva = Reserva::sole()->numero_reserva;

        $this->volverDeMercadoPago()
            ->assertOk()
            ->assertSee('Reserva registrada.')
            ->assertSeeInOrder(['Tu número de reserva', $numeroReserva]);

        $this->assertSame(1, Reserva::count());
        $this->assertSame(3, $this->excursion->fresh()->plazas_retenidas);
    }

    public function test_si_mercado_pago_no_responde_ofrece_reintentar_y_al_reintentar_registra(): void
    {
        $enCurso = $this->guardarReservaEnCurso();
        $this->mercadoPago->noResponder();

        $this->volverDeMercadoPago()
            ->assertOk()
            ->assertSeeInOrder(['No pudimos confirmar el pago', 'número de operación '.self::NUMERO_OPERACION])
            ->assertSee('href="/reservar/confirmada?payment_id='.self::NUMERO_OPERACION.'"', false);

        $this->assertSame(0, Reserva::count());
        $this->assertSame($enCurso, session('reserva_en_curso'));

        $this->mercadoPago->responder($this->pago('approved', $enCurso['id_retencion'].'_Sena'));

        $this->get('/reservar/confirmada?payment_id='.self::NUMERO_OPERACION)->assertRedirect('/reservar/confirmada');

        $this->assertSame(1, Reserva::count());
    }

    public function test_sin_numero_de_operacion_no_consulta_a_mercado_pago_y_vuelve_al_pago(): void
    {
        $this->guardarReservaEnCurso();

        // Sin payment_id, con el «null» que manda Mercado Pago cuando el cliente vuelve sin pagar y con uno que no es un
        // número.
        foreach (['/reservar/confirmada', '/reservar/confirmada?payment_id=null&status=null', '/reservar/confirmada?payment_id=12abc'] as $direccion) {
            $this->get($direccion)->assertRedirect('/reservar/pago');
        }

        $this->assertSame([], $this->mercadoPago->pedidos);
        $this->assertSame(0, Reserva::count());
    }

    public function test_sin_nada_en_la_sesion_avisa_sin_error(): void
    {
        $this->get('/reservar/confirmada')
            ->assertOk()
            ->assertSee('No hay ninguna reserva recién registrada.');

        $this->assertSame([], $this->mercadoPago->pedidos);
    }

    public function test_con_una_referencia_que_no_es_del_sistema_se_comporta_como_sin_pago(): void
    {
        $this->mercadoPago->responder($this->pago('approved', null));

        $this->volverDeMercadoPago()
            ->assertOk()
            ->assertSee('No hay ninguna reserva recién registrada.');

        $this->assertSame(0, Reserva::count());
    }

    // Como vuelve Mercado Pago: con el número de operación y lo que dice del pago, que no se cree.
    private function volverDeMercadoPago(array $parametros = [])
    {
        return $this->get('/reservar/confirmada?'.http_build_query(['payment_id' => self::NUMERO_OPERACION] + $parametros + ['status' => 'approved']));
    }

    // Lo que contesta Mercado Pago al consultar el pago.
    private function pago(string $estado, ?string $referencia, string $tipoPago = 'credit_card'): array
    {
        return [
            'id' => (int) self::NUMERO_OPERACION,
            'status' => $estado,
            'external_reference' => $referencia,
            'payment_type_id' => $tipoPago,
            'currency_id' => 'ARS',
            'transaction_amount' => 151550,
        ];
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
