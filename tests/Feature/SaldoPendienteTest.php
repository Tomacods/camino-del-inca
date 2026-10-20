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
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// Las reglas del pago del saldo en Reserva y en Pago (CU-16), con la reserva 000125-9 de los seeders: Confirmada con el
// saldo adeudado, dos integrantes de un paquete de USD 750, la seña de 750 pagada y el saldo que vence el 01/01/2027.
class SaldoPendienteTest extends TestCase
{
    use RefreshDatabase;

    private const AVISO_SIN_SALDO = 'Esta reserva no tiene saldo pendiente.';

    private Reserva $reserva;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-13 10:00:00'));

        $this->reserva = $this->crearReservaConfirmada();
    }

    public function test_iniciar_pago_saldo_devuelve_el_saldo_y_la_fecha_limite(): void
    {
        $saldo = $this->reserva->iniciarPagoSaldo();

        $this->assertSame(750.0, $saldo['saldo_pendiente']);
        $this->assertSame('2027-01-01 23:59:59', $saldo['fecha_limite_saldo']->format('Y-m-d H:i:s'));
    }

    public function test_el_saldo_conserva_los_centavos_y_se_redondea_a_dos_decimales(): void
    {
        // Como si la seña hubiera sido de 128,11: el saldo es 1.371,89. Sin redondear, la resta da 1371.8899999999999.
        $this->reserva->pagos()->update(['monto' => 128.11]);

        $this->assertSame(1371.89, $this->reserva->fresh()->iniciarPagoSaldo()['saldo_pendiente']);
    }

    public function test_con_el_saldo_abonado_no_hay_saldo_pendiente(): void
    {
        $this->reserva->update(['estado_saldo' => EstadoSaldo::Abonado]);

        $this->expectExceptionObject(new DomainException(self::AVISO_SIN_SALDO));

        $this->reserva->iniciarPagoSaldo();
    }

    public function test_una_reserva_pendiente_no_tiene_saldo_para_pagar(): void
    {
        $this->reserva->update(['estado' => EstadoReserva::Pendiente]);

        $this->expectExceptionObject(new DomainException(self::AVISO_SIN_SALDO));

        $this->reserva->iniciarPagoSaldo();
    }

    public function test_una_reserva_cancelada_no_tiene_saldo_para_pagar(): void
    {
        $this->reserva->update(['estado' => EstadoReserva::Cancelada]);

        $this->expectExceptionObject(new DomainException(self::AVISO_SIN_SALDO));

        $this->reserva->iniciarPagoSaldo();
    }

    public function test_sin_nada_por_pagar_no_hay_saldo_pendiente_aunque_diga_adeudado(): void
    {
        $this->reserva->pagos()->update(['monto' => 1500]);

        $this->expectExceptionObject(new DomainException(self::AVISO_SIN_SALDO));

        $this->reserva->fresh()->iniciarPagoSaldo();
    }

    public function test_al_dia_siguiente_de_la_fecha_limite_avisa_el_vencimiento(): void
    {
        $this->travelTo(Carbon::parse('2027-01-02 00:00:00'));

        $this->expectExceptionObject(new DomainException(
            'El plazo para pagar el saldo venció el 01/01/2027 a las 23:59 y la reserva se cancela por falta de pago.'
        ));

        $this->reserva->iniciarPagoSaldo();
    }

    // A2 se comprueba antes que A3: una reserva ya pagada nunca recibe el aviso de la cancelación por falta de pago.
    public function test_con_el_total_pagado_y_sin_fecha_limite_no_hay_saldo_pendiente(): void
    {
        // Como 000122-3 de los seeders: pagó el total y no tiene fecha límite de saldo.
        $this->reserva->update(['estado_saldo' => EstadoSaldo::Abonado, 'fecha_limite_saldo' => null]);

        $this->expectExceptionObject(new DomainException(self::AVISO_SIN_SALDO));

        $this->reserva->iniciarPagoSaldo();
    }

    public function test_con_el_saldo_pagado_y_la_fecha_limite_pasada_no_hay_saldo_pendiente(): void
    {
        // La fecha límite queda como dato al pagar el saldo: pasado ese día, el aviso sigue siendo el de A2.
        $this->reserva->update(['estado_saldo' => EstadoSaldo::Abonado]);
        $this->travelTo(Carbon::parse('2027-01-02 00:00:00'));

        $this->expectExceptionObject(new DomainException(self::AVISO_SIN_SALDO));

        $this->reserva->iniciarPagoSaldo();
    }

    public function test_con_el_plazo_vencido_la_reserva_no_se_cancela_aca(): void
    {
        $this->travelTo(Carbon::parse('2027-01-02 00:00:00'));

        try {
            $this->reserva->iniciarPagoSaldo();
        } catch (DomainException) {
            // El aviso de A3: la cancelación por falta de pago es de CU-22.
        }

        $this->assertSame(EstadoReserva::Confirmada, $this->reserva->fresh()->estado);
    }

    public function test_la_fecha_limite_vence_a_las_23_59_59_de_ese_dia(): void
    {
        $this->travelTo(Carbon::parse('2027-01-01 23:59:59'));
        $this->assertTrue($this->reserva->verificarFechaLimiteSaldo());

        $this->travelTo(Carbon::parse('2027-01-02 00:00:00'));
        $this->assertFalse($this->reserva->verificarFechaLimiteSaldo());
    }

    public function test_sin_fecha_limite_no_se_puede_pagar_el_saldo(): void
    {
        $this->reserva->fecha_limite_saldo = null;

        $this->assertFalse($this->reserva->verificarFechaLimiteSaldo());
    }

    public function test_actualizar_reserva_deja_el_saldo_abonado_y_lo_guarda(): void
    {
        $pago = $this->reserva->pagos()->sole();

        $this->reserva->actualizarReserva($pago);

        $reserva = $this->reserva->fresh();
        $this->assertSame(EstadoSaldo::Abonado, $reserva->estado_saldo);
        // La fecha límite queda como dato: no se borra al pagar.
        $this->assertSame('2027-01-01 23:59:59', $reserva->fecha_limite_saldo->format('Y-m-d H:i:s'));
    }

    public function test_registrar_pago_saldo_registra_el_saldo_con_su_comprobante(): void
    {
        $pago = Pago::registrarPagoSaldo($this->reserva, 'Tarjeta de débito');

        $this->assertNotNull($pago);
        $this->assertSame(TipoPago::Saldo, $pago->tipo_pago);
        $this->assertSame('750.00', $pago->fresh()->monto);
        $this->assertSame('Tarjeta de débito', $pago->medio_pago);
        $this->assertSame('2026-10-13', $pago->fresh()->fecha->toDateString());
        $this->assertSame('0001-'.str_pad((string) $pago->id_pago, 8, '0', STR_PAD_LEFT), $pago->comprobante->numero_comprobante);
        $this->assertSame('2026-10-13 10:00:00', $pago->comprobante->fecha_emision->format('Y-m-d H:i:s'));

        $reserva = $this->reserva->fresh();
        $this->assertSame(EstadoSaldo::Abonado, $reserva->estado_saldo);
        $this->assertSame(EstadoReserva::Confirmada, $reserva->estado);
        $this->assertSame(0.0, $reserva->calcularSaldoPendiente());
        $this->assertSame('2027-01-01 23:59:59', $reserva->fecha_limite_saldo->format('Y-m-d H:i:s'));
    }

    public function test_registrar_pago_saldo_dos_veces_registra_un_solo_pago(): void
    {
        // Como dos vueltas a la vez: la segunda leyó la reserva, con sus pagos, antes de que la primera registrara.
        $leidaAntes = Reserva::find($this->reserva->id_reserva)->load('pagos');

        $this->assertNotNull(Pago::registrarPagoSaldo($this->reserva, 'Tarjeta de crédito'));

        // Recibe la reserva todavía «Adeudado», pero la lee de nuevo con la fila bloqueada y la encuentra «Abonado».
        $this->assertSame(EstadoSaldo::Adeudado, $leidaAntes->estado_saldo);
        $this->assertNull(Pago::registrarPagoSaldo($leidaAntes, 'Tarjeta de crédito'));

        $this->assertSame(1, $this->reserva->pagos()->where('tipo_pago', TipoPago::Saldo)->count());
    }

    public function test_registrar_pago_saldo_con_el_saldo_abonado_no_registra_nada_aunque_quede_saldo(): void
    {
        // El monto total sale de los precios actuales del paquete: si cambiaran después de pagar, la cuenta podría dar
        // saldo con la reserva ya «Abonado». Lo que manda es estado_saldo.
        $this->reserva->update(['estado_saldo' => EstadoSaldo::Abonado]);

        $this->assertNull(Pago::registrarPagoSaldo($this->reserva, 'Tarjeta de crédito'));

        $this->assertSame(0, Pago::where('tipo_pago', TipoPago::Saldo)->count());
    }

    public function test_registrar_pago_saldo_sin_nada_por_pagar_no_registra_nada(): void
    {
        $this->reserva->pagos()->update(['monto' => 1500]);

        $this->assertNull(Pago::registrarPagoSaldo($this->reserva->fresh(), 'Tarjeta de crédito'));

        $this->assertSame(0, Pago::where('tipo_pago', TipoPago::Saldo)->count());
        $this->assertSame(EstadoSaldo::Adeudado, $this->reserva->fresh()->estado_saldo);
    }

    public function test_registrar_pago_saldo_con_la_reserva_cancelada_no_registra_nada(): void
    {
        $this->reserva->update(['estado' => EstadoReserva::Cancelada]);

        $this->assertNull(Pago::registrarPagoSaldo($this->reserva, 'Tarjeta de crédito'));

        $this->assertSame(1, Pago::count());
        $this->assertSame(EstadoSaldo::Adeudado, $this->reserva->fresh()->estado_saldo);
    }

    public function test_registrar_pago_saldo_con_la_reserva_pendiente_no_registra_nada(): void
    {
        $this->reserva->update(['estado' => EstadoReserva::Pendiente]);

        $this->assertNull(Pago::registrarPagoSaldo($this->reserva, 'Tarjeta de crédito'));

        $this->assertSame(1, Pago::count());
    }

    public function test_si_el_cliente_pago_a_tiempo_se_registra_aunque_vuelva_despues_de_la_fecha_limite(): void
    {
        // Mercado Pago no acepta el pago después de la fecha límite: si lo aprobó, se pagó a tiempo.
        $this->travelTo(Carbon::parse('2027-01-02 00:01:00'));

        $this->assertNotNull(Pago::registrarPagoSaldo($this->reserva, 'Tarjeta de crédito'));
        $this->assertSame(EstadoSaldo::Abonado, $this->reserva->fresh()->estado_saldo);
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

        $excursion = Excursion::create([
            'id_paquete' => $paquete->id_paquete,
            'id_guia' => $usuario->id_usuario,
            'fecha_salida' => '2027-02-01',
            'cupo' => 12,
            'plazas_retenidas' => 0,
        ]);

        $reserva = Reserva::create([
            'id_excursion' => $excursion->id_excursion,
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
}
