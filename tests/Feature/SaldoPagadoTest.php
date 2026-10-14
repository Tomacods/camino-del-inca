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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\MercadoPagoDePrueba;
use Tests\TestCase;

// La vuelta de Mercado Pago del saldo y la pantalla del comprobante (CU-16, pasos 7 a 10 y curso A4), con la reserva
// 000125-9 de los seeders: Confirmada, total de USD 1.500, la seña de 750 pagada y el saldo que vence el 01/01/2027.
class SaldoPagadoTest extends TestCase
{
    use RefreshDatabase;

    private const NUMERO_OPERACION = '183193192230';

    private const DIRECCION = '/mi-reserva/000125-9/saldo-pagado';

    private Excursion $excursion;

    private Reserva $reserva;

    private MercadoPagoDePrueba $mercadoPago;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-13 10:00:00'));

        config(['services.mercadopago.access_token' => 'TOKEN-DE-PRUEBA']);

        $this->reserva = $this->crearReservaConfirmada();
        $this->mercadoPago = MercadoPagoDePrueba::instalar();
    }

    protected function tearDown(): void
    {
        MercadoPagoDePrueba::desinstalar();

        parent::tearDown();
    }

    public function test_con_el_pago_aprobado_registra_el_saldo_y_vuelve_sin_parametros(): void
    {
        Log::spy();
        $this->darAcceso();
        $this->mercadoPago->responder($this->pago('approved', '000125-9_Saldo'));

        $this->volverDeMercadoPago()->assertRedirect(self::DIRECCION);

        $pago = $this->pagoSaldo();
        $this->assertSame('750.00', $pago->monto);
        $this->assertSame('Tarjeta de crédito', $pago->medio_pago);
        $this->assertSame('2026-10-13', $pago->fecha->toDateString());
        $this->assertNotNull($pago->comprobante);

        $reserva = $this->reserva->fresh();
        $this->assertSame(EstadoSaldo::Abonado, $reserva->estado_saldo);
        $this->assertSame(EstadoReserva::Confirmada, $reserva->estado);
        $this->assertSame(0.0, $reserva->calcularSaldoPendiente());
        $this->assertSame('2027-01-01 23:59:59', $reserva->fecha_limite_saldo->format('Y-m-d H:i:s'));

        $this->assertSame(['numero_reserva' => '000125-9', 'numero_operacion' => self::NUMERO_OPERACION], session('saldo_pagado'));
        Log::shouldHaveReceived('info')->once()->withArgs(fn ($mensaje) => str_contains($mensaje, '000125-9')
            && str_contains($mensaje, self::NUMERO_OPERACION));
    }

    public function test_la_pantalla_muestra_el_comprobante_del_saldo(): void
    {
        $this->darAcceso();
        $this->pagarSaldo();

        $comprobante = $this->pagoSaldo()->comprobante;

        $this->get(self::DIRECCION)->assertOk()->assertSee('Saldo pagado.');

        // Lo que se ve, con Livewire::test: dibuja sólo el componente, sin el menú del layout (que también lleva a
        // /mi-reserva) ni los datos internos de Livewire (que también tienen el número de reserva).
        $this->abrirPantalla()
            ->assertSeeInOrder(['Reserva', '000125-9', EstadoReserva::Confirmada->value])
            ->assertSee('El saldo quedó «Abonado»: no queda nada por pagar.')
            ->assertSeeInOrder(['Comprobante', $comprobante->numero_comprobante, '13/10/2026 10:00', TipoPago::Saldo->value, 'Tarjeta de crédito', 'USD 750,00'])
            ->assertSeeHtmlInOrder(['href="/mi-reserva"', 'Ir a Mi reserva']);
    }

    public function test_el_medio_de_pago_es_el_que_informa_mercado_pago(): void
    {
        $this->darAcceso();
        $this->mercadoPago->responder($this->pago('approved', '000125-9_Saldo', 'account_money'));

        $this->volverDeMercadoPago()->assertRedirect(self::DIRECCION);

        $this->assertSame('Dinero en cuenta de Mercado Pago', $this->pagoSaldo()->medio_pago);
        $this->get(self::DIRECCION)->assertSeeInOrder(['Medio de pago', 'Dinero en cuenta de Mercado Pago']);
    }

    public function test_despues_de_pagar_mi_reserva_muestra_el_saldo_abonado_y_no_ofrece_pagarlo(): void
    {
        $this->darAcceso();
        $this->pagarSaldo();

        // El pago nuevo, al final de la lista de pagos: después de la seña, con su fecha.
        Livewire::test('consultar-reserva')
            ->assertSeeInOrder(['Abonado · USD 0', TipoPago::Sena->value, '01/10/2026', TipoPago::Saldo->value, '13/10/2026', 'USD 750'])
            ->assertDontSee('Pagar saldo')
            ->assertDontSeeHtml('/pagar-saldo');
    }

    public function test_con_el_pago_rechazado_no_registra_nada_y_vuelve_al_pago_con_el_aviso(): void
    {
        $this->darAcceso();
        $this->mercadoPago->responder($this->pago('rejected', '000125-9_Saldo'));

        $this->volverDeMercadoPago(['status' => 'rejected'])->assertRedirect('/mi-reserva/000125-9/pagar-saldo');

        $this->assertNoSeRegistroElSaldo();

        Livewire::test('pagar-saldo', ['numeroReserva' => '000125-9'])
            ->assertSee('El pago fue rechazado')
            ->assertSee('Pagar el saldo con Mercado Pago');
    }

    public function test_no_se_le_cree_a_la_direccion_si_mercado_pago_dice_que_se_rechazo(): void
    {
        $this->darAcceso();
        $this->mercadoPago->responder($this->pago('rejected', '000125-9_Saldo'));

        $this->volverDeMercadoPago(['status' => 'approved', 'collection_status' => 'approved'])
            ->assertRedirect('/mi-reserva/000125-9/pagar-saldo');

        $this->assertNoSeRegistroElSaldo();
    }

    public function test_un_pago_del_saldo_de_otra_reserva_no_registra_nada(): void
    {
        $this->darAcceso();
        $otra = $this->crearOtraReserva('000122-3', 'valentina.diaz@mail.com');
        $this->mercadoPago->responder($this->pago('approved', '000122-3_Saldo'));

        $this->volverDeMercadoPago()
            ->assertOk()
            // El número tiene que estar en el aviso: también figura en los datos internos de Livewire, al principio.
            ->assertSeeInOrder(['No pudimos registrar el pago', 'no corresponde al saldo de la reserva', self::NUMERO_OPERACION]);

        $this->assertNoSeRegistroElSaldo();
        $this->assertSame(EstadoSaldo::Adeudado, $otra->fresh()->estado_saldo);
    }

    public function test_un_pago_de_una_reserva_nueva_no_registra_el_saldo(): void
    {
        $this->darAcceso();

        // Con la referencia de CU-15, o sin referencia.
        foreach ([Str::uuid().'_Sena', Str::uuid().'_Total', null] as $referencia) {
            $this->mercadoPago->responder($this->pago('approved', $referencia));

            $this->volverDeMercadoPago()
                ->assertOk()
                ->assertSeeInOrder(['No pudimos registrar el pago', 'Comunicate con la agencia', self::NUMERO_OPERACION]);
        }

        $this->assertNoSeRegistroElSaldo();
    }

    public function test_el_saldo_de_esta_reserva_no_se_registra_desde_la_direccion_de_otra(): void
    {
        $otra = $this->crearOtraReserva('000122-3', 'valentina.diaz@mail.com');
        $this->mercadoPago->responder($this->pago('approved', '000125-9_Saldo'));

        // 000122-3 también tiene saldo adeudado: si se registrara en la reserva de la dirección, se notaría.
        $this->volverDeMercadoPago([], '000122-3')
            ->assertOk()
            ->assertSeeInOrder(['No pudimos registrar el pago', 'no corresponde al saldo de la reserva', '000122-3', self::NUMERO_OPERACION]);

        $this->assertNoSeRegistroElSaldo();
        $this->assertSame(EstadoSaldo::Adeudado, $otra->fresh()->estado_saldo);
    }

    public function test_con_el_saldo_ya_pagado_un_segundo_pago_no_se_registra_y_muestra_su_numero_de_operacion(): void
    {
        $this->darAcceso();
        $this->pagarSaldo();

        // El cliente también pagó desde otra pestaña: otro pago aprobado, con otro número de operación.
        $this->mercadoPago->responder($this->pago('approved', '000125-9_Saldo'));

        $this->volverDeMercadoPago(['payment_id' => '183193192231'])
            ->assertOk()
            ->assertSeeInOrder(['El saldo ya estaba pagado', 'Si pagaste el saldo dos veces, la devolución se gestiona con la agencia', '183193192231']);

        $this->assertSame(1, $this->reserva->pagos()->where('tipo_pago', TipoPago::Saldo)->count());
    }

    public function test_la_misma_vuelta_desde_otra_sesion_no_dice_que_el_pago_quedo_sin_registrar(): void
    {
        $this->darAcceso();
        $this->pagarSaldo();

        // La misma vuelta, con la sesión vencida o desde otro navegador: no tiene 'saldo_pagado' y se le pregunta otra vez
        // a Mercado Pago. No se puede distinguir de un segundo pago, así que el aviso cubre los dos casos.
        $this->flushSession();
        $this->mercadoPago->responder($this->pago('approved', '000125-9_Saldo'));

        $this->volverDeMercadoPago()
            ->assertOk()
            ->assertSeeInOrder(['El saldo ya estaba pagado', 'Si volviste de Mercado Pago más de una vez con el mismo pago, no tenés que hacer', self::NUMERO_OPERACION])
            ->assertDontSee('No pudimos registrar el pago');

        $this->assertSame(1, $this->reserva->pagos()->where('tipo_pago', TipoPago::Saldo)->count());
    }

    public function test_si_otra_pestana_registra_el_saldo_mientras_tanto_avisa_que_ya_estaba_pagado(): void
    {
        $this->darAcceso();
        $this->mercadoPago->responder($this->pago('approved', '000125-9_Saldo'));

        // La otra pestaña registra su pago justo después de que esta vuelta buscó la reserva, que todavía decía «Adeudado».
        // retrieved se dispara cada vez que se lee una reserva de la base: la primera es la búsqueda de esta vuelta.
        $otraPestanaPago = false;
        Reserva::retrieved(function (Reserva $reserva) use (&$otraPestanaPago) {
            if (! $otraPestanaPago && $reserva->numero_reserva === '000125-9') {
                $otraPestanaPago = true;
                Pago::registrarPagoSaldo($reserva, 'Tarjeta de débito');
            }
        });

        $this->volverDeMercadoPago()
            ->assertOk()
            ->assertSee('El saldo ya estaba pagado')
            ->assertDontSee('No pudimos registrar el pago');

        $this->assertTrue($otraPestanaPago);
        $this->assertSame(1, $this->reserva->pagos()->where('tipo_pago', TipoPago::Saldo)->count());
    }

    public function test_el_numero_de_operacion_ya_registrado_no_sirve_en_la_direccion_de_otra_reserva(): void
    {
        $this->crearOtraReserva('000122-3', 'valentina.diaz@mail.com');
        $this->darAcceso();
        $this->pagarSaldo();

        // El mismo payment_id, pegado a mano en la dirección de otra reserva: no es la misma vuelta, se consulta y no
        // corresponde.
        $this->mercadoPago->responder($this->pago('approved', '000125-9_Saldo'));

        $this->volverDeMercadoPago([], '000122-3')
            ->assertOk()
            ->assertSeeInOrder(['no corresponde al saldo de la reserva', '000122-3', self::NUMERO_OPERACION]);

        $this->assertCount(2, $this->mercadoPago->pedidos);
    }

    public function test_si_el_cliente_pago_a_tiempo_se_registra_aunque_vuelva_despues_de_la_fecha_limite(): void
    {
        // Confirmó el 01/01/2027 a las 23:57 (el pedido de cobro vence a las 23:59:59), pagó a las 23:59 y volvió después
        // de la medianoche: Mercado Pago aprobó, así que pagó a tiempo.
        $this->darAcceso();
        $this->travelTo(Carbon::parse('2027-01-02 00:00:30'));

        $this->pagarSaldo();

        $this->assertSame(EstadoSaldo::Abonado, $this->reserva->fresh()->estado_saldo);
    }

    public function test_con_la_reserva_cancelada_mientras_pagaba_no_registra_el_saldo(): void
    {
        $this->darAcceso();
        $this->reserva->update(['estado' => EstadoReserva::Cancelada]);
        $this->mercadoPago->responder($this->pago('approved', '000125-9_Saldo'));

        $this->volverDeMercadoPago()
            ->assertOk()
            ->assertSeeInOrder(['No pudimos registrar el pago', 'La devolución del dinero se gestiona con la agencia', self::NUMERO_OPERACION]);

        $this->assertNoSeRegistroElSaldo();
    }

    public function test_con_la_reserva_cambiada_de_excursion_mientras_pagaba_no_registra_el_saldo(): void
    {
        // Con el cobro abierto en Mercado Pago, el cliente cambia la reserva a otra salida del paquete (CU-20): vuelve a
        // «Pendiente» y R-16 ya no deja cobrarle el saldo. La devolución es manual (decisión del grupo del 13/09).
        $this->darAcceso();
        Excursion::create([
            'id_paquete' => $this->excursion->id_paquete,
            'id_guia' => $this->excursion->id_guia,
            'fecha_salida' => '2027-03-01',
            'cupo' => 12,
            'plazas_retenidas' => 0,
        ]);
        $cambio = $this->reserva->elegirExcursionDestino(Carbon::parse('2027-03-01'));
        $this->reserva->modificarExcursion($cambio['destino']);
        $this->mercadoPago->responder($this->pago('approved', '000125-9_Saldo'));

        $this->volverDeMercadoPago()
            ->assertOk()
            ->assertSeeInOrder(['No pudimos registrar el pago', 'La devolución del dinero se gestiona con la agencia', self::NUMERO_OPERACION]);

        $this->assertNoSeRegistroElSaldo();
        $this->assertSame(EstadoReserva::Pendiente, $this->reserva->fresh()->estado);
    }

    public function test_la_misma_vuelta_dos_veces_registra_un_solo_pago_y_muestra_el_comprobante(): void
    {
        $this->darAcceso();
        $this->pagarSaldo();

        // Atrás y adelante en el navegador: la misma dirección, con el mismo número de operación.
        $this->volverDeMercadoPago()->assertRedirect(self::DIRECCION);

        $this->get(self::DIRECCION)->assertOk()->assertSee('Saldo pagado.');
        $this->assertSame(1, $this->reserva->pagos()->where('tipo_pago', TipoPago::Saldo)->count());
        // Mercado Pago se consultó una sola vez.
        $this->assertCount(1, $this->mercadoPago->pedidos);
    }

    public function test_si_mercado_pago_no_responde_ofrece_reintentar_y_al_reintentar_registra(): void
    {
        $this->darAcceso();
        $this->mercadoPago->noResponder();

        $this->volverDeMercadoPago()
            ->assertOk()
            ->assertSeeInOrder(['No pudimos confirmar el pago', 'número de operación '.self::NUMERO_OPERACION])
            ->assertSee('href="'.self::DIRECCION.'?payment_id='.self::NUMERO_OPERACION.'"', false);

        $this->assertNoSeRegistroElSaldo();

        $this->mercadoPago->responder($this->pago('approved', '000125-9_Saldo'));

        $this->get(self::DIRECCION.'?payment_id='.self::NUMERO_OPERACION)->assertRedirect(self::DIRECCION);

        $this->assertSame(EstadoSaldo::Abonado, $this->reserva->fresh()->estado_saldo);
    }

    public function test_sin_acceso_en_la_sesion_registra_el_pago_y_no_muestra_los_datos_de_la_reserva(): void
    {
        $this->mercadoPago->responder($this->pago('approved', '000125-9_Saldo'));

        $this->volverDeMercadoPago()->assertRedirect(self::DIRECCION);

        $this->assertSame(EstadoSaldo::Abonado, $this->reserva->fresh()->estado_saldo);

        $this->get(self::DIRECCION)->assertOk()->assertSee('Registramos tu pago');

        $this->abrirPantalla()
            ->assertSeeInOrder(['El pago del saldo de la reserva N.º', '000125-9', 'quedó registrado'])
            ->assertSeeHtmlInOrder(['href="/mi-reserva"', 'Ir a Mi reserva'])
            ->assertDontSee('Comprobante')
            ->assertDontSee('Tarjeta de crédito')
            ->assertDontSee($this->pagoSaldo()->comprobante->numero_comprobante);
    }

    public function test_con_el_acceso_de_otra_reserva_no_muestra_los_datos_de_esta(): void
    {
        $this->crearOtraReserva('000122-3', 'valentina.diaz@mail.com');
        $this->darAcceso('valentina.diaz@mail.com', '000122-3');
        $this->mercadoPago->responder($this->pago('approved', '000125-9_Saldo'));

        $this->volverDeMercadoPago()->assertRedirect(self::DIRECCION);

        $this->abrirPantalla()
            ->assertSee('Registramos tu pago')
            ->assertDontSee('Comprobante')
            ->assertDontSee('Tarjeta de crédito');
    }

    public function test_sin_numero_de_operacion_no_consulta_a_mercado_pago_y_vuelve_al_pago_del_saldo(): void
    {
        $this->darAcceso();

        // Sin payment_id, con el «null» que manda Mercado Pago cuando el cliente vuelve sin pagar, con uno que no es un
        // número y con uno de 19 cifras, que no entra en un entero.
        foreach ([
            self::DIRECCION,
            self::DIRECCION.'?payment_id=null&status=null',
            self::DIRECCION.'?payment_id=12abc',
            self::DIRECCION.'?payment_id=9999999999999999999',
        ] as $direccion) {
            $this->get($direccion)->assertRedirect('/mi-reserva/000125-9/pagar-saldo');
        }

        $this->assertSame([], $this->mercadoPago->pedidos);
        $this->assertNoSeRegistroElSaldo();
    }

    public function test_despues_de_pagar_volver_sin_numero_de_operacion_sigue_mostrando_el_comprobante(): void
    {
        $this->darAcceso();
        $this->pagarSaldo();

        foreach ([self::DIRECCION, self::DIRECCION.'?payment_id=null&status=null'] as $direccion) {
            $this->get($direccion)
                ->assertOk()
                ->assertSee('Saldo pagado.')
                ->assertSee('Comprobante');
        }

        $this->assertCount(1, $this->mercadoPago->pedidos);
    }

    public function test_el_saldo_pagado_de_otra_reserva_no_se_muestra_aca(): void
    {
        $this->crearOtraReserva('000122-3', 'valentina.diaz@mail.com');
        $this->darAcceso();
        $this->pagarSaldo();

        $this->get('/mi-reserva/000122-3/saldo-pagado')->assertRedirect('/mi-reserva/000122-3/pagar-saldo');
    }

    // La pantalla después de la vuelta, sin parámetros: con 'saldo_pagado' en la sesión, muestra el pago registrado.
    private function abrirPantalla()
    {
        return Livewire::test('saldo-pagado', ['numeroReserva' => '000125-9']);
    }

    // Paga el saldo por el camino normal: pago aprobado y vuelta.
    private function pagarSaldo(): void
    {
        $this->mercadoPago->responder($this->pago('approved', '000125-9_Saldo'));
        $this->volverDeMercadoPago()->assertRedirect(self::DIRECCION);
    }

    private function pagoSaldo(): Pago
    {
        return $this->reserva->pagos()->where('tipo_pago', TipoPago::Saldo)->sole();
    }

    private function assertNoSeRegistroElSaldo(): void
    {
        $this->assertSame(0, Pago::where('tipo_pago', TipoPago::Saldo)->count());
        $this->assertSame(EstadoSaldo::Adeudado, $this->reserva->fresh()->estado_saldo);
    }

    // Como vuelve Mercado Pago: con el número de operación y lo que dice del pago, que no se cree.
    private function volverDeMercadoPago(array $parametros = [], string $numeroReserva = '000125-9')
    {
        return $this->get('/mi-reserva/'.$numeroReserva.'/saldo-pagado?'
            .http_build_query($parametros + ['payment_id' => self::NUMERO_OPERACION, 'status' => 'approved']));
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
            'transaction_amount' => 1095000,
        ];
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
