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
use App\Models\Paquete;
use App\Models\Recorrido;
use App\Models\Reserva;
use App\Models\Usuario;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Mejora 3 de la devolución del 18/11: modificar los precios de un paquete (CU-02) no cambia para atrás el monto de una
// reserva que ya viajó. R-02 sólo bloquea la modificación con reservas activas, así que un paquete con reservas
// «Finalizada» se puede modificar.
class ReservaFinalizadaNoRetroactivaTest extends TestCase
{
    use RefreshDatabase;

    private const PRECIOS_NUEVOS = [
        'precio_base' => 900,
        'costo_noche_extra_cusco' => 80,
        'costo_equipo_camping' => 50,
    ];

    private Paquete $paquete;

    private Usuario $guia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-19 10:00:00');

        $recorrido = Recorrido::create(['nombre' => 'Camino Inca de 4 días', 'duracion_dias' => 4, 'cantidad_campings' => 3]);

        $this->paquete = Paquete::create([
            'id_recorrido' => $recorrido->id_recorrido,
            'nombre' => 'Camino Inca Clásico',
            'precio_base' => 750,
            'costo_noche_extra_cusco' => 60,
            'costo_equipo_camping' => 45,
            'cantidad_porteadores' => 6,
            'estado' => EstadoPaquete::Activo,
            'fecha_creacion' => '2026-01-05',
        ]);

        $this->guia = Usuario::create(['correo' => 'guia@caminodelinca.test', 'password' => 'guia1234', 'rol' => Rol::Guia]);
        Guia::create(['id_usuario' => $this->guia->id_usuario, 'nombre' => 'Rosa', 'apellido' => 'Quispe']);
    }

    // El caso que pidieron probar las profes. Con los precios de antes: 3 × 750 + 2 noches × 3 × 60 + 2 equipos × 45 =
    // 2700, pagados en seña y saldo. Con los precios nuevos daría 3280.
    public function test_el_monto_de_una_reserva_finalizada_no_cambia_al_modificar_los_precios_del_paquete(): void
    {
        $reserva = $this->crearReserva('2026-09-07', EstadoReserva::Finalizada, EstadoSaldo::Abonado, [1350, 1350]);

        $this->assertSame(2700.0, $reserva->calcularMontoTotal());
        $this->assertSame(0.0, $reserva->calcularSaldoPendiente());

        $this->paquete->modificarPaquete(self::PRECIOS_NUEVOS);

        $this->assertSame('900.00', $this->paquete->refresh()->precio_base);

        $reserva = Reserva::findOrFail($reserva->id_reserva);
        $this->assertSame(2700.0, $reserva->calcularMontoTotal());
        $this->assertSame(0.0, $reserva->calcularSaldoPendiente());
    }

    // Si CU-22 no llegó a cancelarla, una reserva puede finalizar con el saldo «Adeudado» (finalizarReserva() no lo
    // controla). Ahí el monto sigue saliendo del paquete, para que se vea la deuda: con sólo la seña, el saldo es 1350.
    public function test_una_reserva_finalizada_con_saldo_adeudado_sigue_mostrando_la_deuda(): void
    {
        $reserva = $this->crearReserva('2026-09-07', EstadoReserva::Finalizada, EstadoSaldo::Adeudado, [1350]);

        $this->assertSame(2700.0, $reserva->calcularMontoTotal());
        $this->assertSame(1350.0, $reserva->calcularSaldoPendiente());
    }

    // Una reserva activa sigue calculando con los precios del paquete: con la seña sola, el monto total es el del
    // paquete y no lo pagado. Y el paquete no se puede modificar mientras la tenga (R-02).
    public function test_una_reserva_activa_sigue_calculando_con_los_precios_del_paquete(): void
    {
        $reserva = $this->crearReserva('2027-01-18', EstadoReserva::Confirmada, EstadoSaldo::Adeudado, [1350]);

        $this->assertSame(2700.0, $reserva->calcularMontoTotal());
        $this->assertSame(1350.0, $reserva->calcularSaldoPendiente());

        $this->assertThrows(
            fn () => $this->paquete->modificarPaquete(self::PRECIOS_NUEVOS),
            DomainException::class,
            'Tiene reservas en estado «Pendiente», «Confirmada» o «Sin Permiso».',
        );

        $this->assertSame('750.00', $this->paquete->refresh()->precio_base);

        $reserva = Reserva::findOrFail($reserva->id_reserva);
        $this->assertSame(2700.0, $reserva->calcularMontoTotal());
        $this->assertSame(1350.0, $reserva->calcularSaldoPendiente());
    }

    // 3 integrantes, 2 con equipo de camping, y una noche extra antes y una después, como la reserva del prototipo.
    private function crearReserva(string $fechaSalida, EstadoReserva $estado, EstadoSaldo $estadoSaldo, array $pagos): Reserva
    {
        $excursion = Excursion::create([
            'id_paquete' => $this->paquete->id_paquete,
            'id_guia' => $this->guia->id_usuario,
            'fecha_salida' => $fechaSalida,
            'cupo' => 12,
            'plazas_retenidas' => 0,
        ]);

        $fechaLimite = Reserva::calcularFechaLimite($fechaSalida);

        $reserva = Reserva::create([
            'id_excursion' => $excursion->id_excursion,
            'numero_reserva' => '000124-7',
            'correo_electronico' => 'lucia.fernandez@mail.com',
            'fecha_reserva' => '2026-05-04 10:30:00',
            'estado' => $estado,
            'estado_saldo' => $estadoSaldo,
            'noches_extra_antes' => 1,
            'noches_extra_despues' => 1,
            'fecha_limite_saldo' => $fechaLimite,
            'fecha_limite_confirmacion' => $fechaLimite,
        ]);

        foreach ([['Lucía', 'AAG604118', true], ['Martín', 'AAG604119', true], ['Sofía', 'AAG611025', false]] as [$nombre, $documento, $equipo]) {
            $reserva->excursionistas()->create([
                'nombre' => $nombre,
                'apellido' => 'Fernández',
                'documento_pasaporte' => $documento,
                'equipo_camping' => $equipo,
                'estado_permiso' => EstadoPermiso::Obtenido,
            ]);
        }

        foreach ($pagos as $posicion => $monto) {
            $reserva->pagos()->create([
                'fecha' => '2026-05-04',
                'monto' => $monto,
                'tipo_pago' => $posicion === 0 ? TipoPago::Sena : TipoPago::Saldo,
                'medio_pago' => 'Tarjeta de crédito',
            ]);
        }

        return $reserva;
    }
}
