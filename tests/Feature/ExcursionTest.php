<?php

namespace Tests\Feature;

use App\Models\Excursion;
use App\Models\Excursionista;
use App\Models\Guia;
use App\Models\Paquete;
use App\Models\Recorrido;
use App\Models\Reserva;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ExcursionTest extends TestCase
{
    use RefreshDatabase;

    private int $numeroReservaSiguiente = 1;

    public function test_suma_los_excursionistas_de_las_reservas_pendientes_confirmadas_y_sin_permiso(): void
    {
        $excursion = $this->crearExcursion(cupo: 12);
        $this->crearReserva($excursion, Reserva::ESTADO_PENDIENTE, 3);
        $this->crearReserva($excursion, Reserva::ESTADO_CONFIRMADA, 2);
        $this->crearReserva($excursion, Reserva::ESTADO_SIN_PERMISO, 1);

        $this->assertSame(6, $excursion->sumarPlazasReservadas());
    }

    public function test_no_suma_las_reservas_canceladas_ni_finalizadas(): void
    {
        $excursion = $this->crearExcursion(cupo: 12);
        $this->crearReserva($excursion, Reserva::ESTADO_CANCELADA, 3);
        $this->crearReserva($excursion, Reserva::ESTADO_FINALIZADA, 2);

        $this->assertSame(0, $excursion->sumarPlazasReservadas());
    }

    public function test_no_suma_las_reservas_de_otra_excursion(): void
    {
        $excursion = $this->crearExcursion(cupo: 12);
        $otraExcursion = $this->crearExcursion(cupo: 12, fechaSalida: '2027-01-11');
        $this->crearReserva($otraExcursion, Reserva::ESTADO_PENDIENTE, 4);

        $this->assertSame(0, $excursion->sumarPlazasReservadas());
    }

    public function test_el_cupo_disponible_descuenta_las_plazas_reservadas_y_las_retenidas(): void
    {
        $excursion = $this->crearExcursion(cupo: 12, plazasRetenidas: 2);
        $this->crearReserva($excursion, Reserva::ESTADO_CONFIRMADA, 3);

        $this->assertSame(7, $excursion->obtenerCupoDisponible());
    }

    public function test_tiene_cupo_si_la_cantidad_es_justo_el_cupo_disponible(): void
    {
        $excursion = $this->crearExcursion(cupo: 8, plazasRetenidas: 1);
        $this->crearReserva($excursion, Reserva::ESTADO_PENDIENTE, 3);

        $this->assertTrue($excursion->tieneCupoPara(4));
    }

    public function test_no_tiene_cupo_si_la_cantidad_supera_el_cupo_disponible(): void
    {
        $excursion = $this->crearExcursion(cupo: 8, plazasRetenidas: 1);
        $this->crearReserva($excursion, Reserva::ESTADO_PENDIENTE, 3);

        $this->assertFalse($excursion->tieneCupoPara(5));
    }

    public function test_admite_reserva_si_la_salida_es_exactamente_a_tres_meses(): void
    {
        $excursion = $this->crearExcursion(cupo: 12, fechaSalida: '2027-01-04');

        $this->assertTrue($excursion->admiteReserva('2026-10-04 18:30:00'));
    }

    public function test_admite_reserva_si_la_salida_es_despues_de_tres_meses(): void
    {
        $excursion = $this->crearExcursion(cupo: 12, fechaSalida: '2027-01-04');

        $this->assertTrue($excursion->admiteReserva('2026-09-30'));
    }

    public function test_no_admite_reserva_si_la_salida_es_un_dia_antes_de_los_tres_meses(): void
    {
        $excursion = $this->crearExcursion(cupo: 12, fechaSalida: '2027-01-04');

        $this->assertFalse($excursion->admiteReserva('2026-10-05'));
    }

    public function test_no_admite_reserva_si_el_paquete_esta_inactivo(): void
    {
        $excursion = $this->crearExcursion(cupo: 12, fechaSalida: '2027-01-04', estadoPaquete: Paquete::ESTADO_INACTIVO);

        $this->assertFalse($excursion->admiteReserva('2026-09-30'));
    }

    public function test_los_meses_de_anticipacion_salen_de_la_configuracion(): void
    {
        config(['reserva.meses_anticipacion_minima' => 4]);
        $excursion = $this->crearExcursion(cupo: 12, fechaSalida: '2027-01-04');

        $this->assertFalse($excursion->admiteReserva('2026-10-04'));
    }

    public function test_retiene_el_cupo_si_alcanza_justo(): void
    {
        $excursion = $this->crearExcursion(cupo: 8, plazasRetenidas: 2);
        $this->crearReserva($excursion, Reserva::ESTADO_CONFIRMADA, 3);

        $this->assertTrue($excursion->retenerCupo(3));
        $this->assertSame(5, $excursion->plazas_retenidas);
        $this->assertSame(5, $excursion->fresh()->plazas_retenidas);
        $this->assertSame(0, $excursion->obtenerCupoDisponible());
    }

    public function test_no_retiene_el_cupo_si_no_alcanza(): void
    {
        $excursion = $this->crearExcursion(cupo: 8, plazasRetenidas: 2);
        $this->crearReserva($excursion, Reserva::ESTADO_CONFIRMADA, 3);

        $this->assertFalse($excursion->retenerCupo(4));
        $this->assertSame(2, $excursion->fresh()->plazas_retenidas);
    }

    // Simula a otro cliente que retuvo plazas después de que esta instancia se cargó: la decisión se toma con la fila
    // releída de la base, no con el valor viejo que tiene el objeto en memoria.
    public function test_al_retener_tiene_en_cuenta_lo_que_retuvo_otro_cliente(): void
    {
        $excursion = $this->crearExcursion(cupo: 8);
        Excursion::find($excursion->id_excursion)->retenerCupo(6);

        $this->assertFalse($excursion->retenerCupo(3));
        $this->assertSame(6, $excursion->fresh()->plazas_retenidas);
    }

    public function test_no_retiene_cero_plazas(): void
    {
        $excursion = $this->crearExcursion(cupo: 8, plazasRetenidas: 2);

        $this->assertThrows(
            fn () => $excursion->retenerCupo(0),
            InvalidArgumentException::class,
            'La cantidad de plazas a retener tiene que ser 1 o más.',
        );
        $this->assertSame(2, $excursion->fresh()->plazas_retenidas);
    }

    public function test_no_retiene_una_cantidad_negativa_de_plazas(): void
    {
        $excursion = $this->crearExcursion(cupo: 8, plazasRetenidas: 2);

        $this->assertThrows(
            fn () => $excursion->retenerCupo(-1),
            InvalidArgumentException::class,
            'La cantidad de plazas a retener tiene que ser 1 o más.',
        );
        $this->assertSame(2, $excursion->fresh()->plazas_retenidas);
    }

    public function test_libera_el_cupo_retenido(): void
    {
        $excursion = $this->crearExcursion(cupo: 8, plazasRetenidas: 5);

        $excursion->liberarCupoRetenido(3);

        $this->assertSame(2, $excursion->plazas_retenidas);
        $this->assertSame(2, $excursion->fresh()->plazas_retenidas);
    }

    public function test_liberar_mas_de_lo_retenido_deja_las_plazas_retenidas_en_cero(): void
    {
        $excursion = $this->crearExcursion(cupo: 8, plazasRetenidas: 2);

        $excursion->liberarCupoRetenido(5);

        $this->assertSame(0, $excursion->plazas_retenidas);
        $this->assertSame(0, $excursion->fresh()->plazas_retenidas);
    }

    public function test_no_libera_cero_plazas(): void
    {
        $excursion = $this->crearExcursion(cupo: 8, plazasRetenidas: 2);

        $this->assertThrows(
            fn () => $excursion->liberarCupoRetenido(0),
            InvalidArgumentException::class,
            'La cantidad de plazas a liberar tiene que ser 1 o más.',
        );
        $this->assertSame(2, $excursion->fresh()->plazas_retenidas);
    }

    // Restar una cantidad negativa sumaría plazas retenidas sin controlar el cupo.
    public function test_no_libera_una_cantidad_negativa_de_plazas(): void
    {
        $excursion = $this->crearExcursion(cupo: 8, plazasRetenidas: 2);

        $this->assertThrows(
            fn () => $excursion->liberarCupoRetenido(-3),
            InvalidArgumentException::class,
            'La cantidad de plazas a liberar tiene que ser 1 o más.',
        );
        $this->assertSame(2, $excursion->fresh()->plazas_retenidas);
    }

    private function crearExcursion(
        int $cupo,
        int $plazasRetenidas = 0,
        string $fechaSalida = '2027-01-04',
        string $estadoPaquete = Paquete::ESTADO_ACTIVO,
    ): Excursion {
        $recorrido = Recorrido::create([
            'nombre' => 'Recorrido '.$fechaSalida,
            'duracion_dias' => 4,
            'cantidad_campings' => 3,
        ]);

        $paquete = Paquete::create([
            'id_recorrido' => $recorrido->id_recorrido,
            'nombre' => 'Paquete '.$fechaSalida,
            'precio_base' => 650,
            'costo_noche_extra_cusco' => 40,
            'costo_equipo_camping' => 25,
            'cantidad_porteadores' => 2,
            'estado' => $estadoPaquete,
            'fecha_creacion' => '2026-01-05',
        ]);

        $usuario = Usuario::create([
            'correo' => 'guia'.$fechaSalida.'@caminodelinca.test',
            'password' => 'guia1234',
            'rol' => Usuario::ROL_GUIA,
        ]);

        Guia::create(['id_usuario' => $usuario->id_usuario, 'nombre' => 'Rosa', 'apellido' => 'Quispe']);

        return Excursion::create([
            'id_paquete' => $paquete->id_paquete,
            'id_guia' => $usuario->id_usuario,
            'fecha_salida' => $fechaSalida,
            'cupo' => $cupo,
            'plazas_retenidas' => $plazasRetenidas,
        ])->fresh();
    }

    private function crearReserva(Excursion $excursion, string $estado, int $cantidadExcursionistas): Reserva
    {
        $reserva = Reserva::create([
            'id_excursion' => $excursion->id_excursion,
            'numero_reserva' => sprintf('%06d-0', $this->numeroReservaSiguiente++),
            'correo_electronico' => 'titular@mail.com',
            'fecha_reserva' => '2026-10-01 10:00:00',
            'estado' => $estado,
            'estado_saldo' => Reserva::ESTADO_SALDO_ADEUDADO,
            'noches_extra_antes' => 0,
            'noches_extra_despues' => 0,
            'fecha_limite_saldo' => '2026-12-04 23:59:59',
            'fecha_limite_confirmacion' => '2026-12-04 23:59:59',
        ]);

        for ($numero = 1; $numero <= $cantidadExcursionistas; $numero++) {
            Excursionista::create([
                'id_reserva' => $reserva->id_reserva,
                'nombre' => 'Integrante',
                'apellido' => (string) $numero,
                'documento_pasaporte' => 'PAS'.$numero,
                'equipo_camping' => false,
                'estado_permiso' => Excursionista::ESTADO_PERMISO_PENDIENTE,
            ]);
        }

        return $reserva;
    }
}
