<?php

namespace Tests\Feature;

use App\Enums\EstadoPaquete;
use App\Enums\EstadoReserva;
use App\Enums\EstadoSaldo;
use App\Enums\Rol;
use App\Models\Excursion;
use App\Models\Guia;
use App\Models\Paquete;
use App\Models\Recorrido;
use App\Models\Reserva;
use App\Models\Usuario;
use App\Models\Valoracion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// Reseñas públicas: el promedio en el listado y la página de comentarios de cada paquete.
class ComentariosPaqueteTest extends TestCase
{
    use RefreshDatabase;

    private Recorrido $recorrido;

    private Guia $guia;

    private int $reservasCreadas = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-08');

        $this->recorrido = Recorrido::create(['nombre' => 'Camino Inca de 4 días', 'duracion_dias' => 4, 'cantidad_campings' => 3]);
        $usuario = Usuario::create(['correo' => 'guia@caminodelinca.test', 'password' => 'guia1234', 'rol' => Rol::Guia]);
        $this->guia = Guia::create(['id_usuario' => $usuario->id_usuario, 'nombre' => 'Rosa', 'apellido' => 'Quispe']);
    }

    public function test_el_listado_muestra_el_promedio_y_la_cantidad_de_valoraciones(): void
    {
        $paquete = $this->crearPaquete('Camino Inca Clásico');
        $this->valorar($paquete, 4, 'Muy buena guía.', '2026-09-20 10:00:00');
        $this->valorar($paquete, 5, 'Todo perfecto.', '2026-09-21 10:00:00');

        $this->get('/paquetes')
            ->assertSee('4,5')
            ->assertSee('de 5 · 2 valoraciones')
            ->assertSee('href="/paquetes/'.$paquete->id_paquete.'/comentarios"', false);
    }

    public function test_un_paquete_sin_valoraciones_lo_dice(): void
    {
        $this->crearPaquete('Camino Inca Clásico');

        $this->get('/paquetes')->assertSee('Todavía sin valoraciones');
    }

    // El promedio cuenta todas las valoraciones, también las que no tienen comentario.
    public function test_el_promedio_incluye_las_valoraciones_sin_comentario(): void
    {
        $paquete = $this->crearPaquete('Camino Inca Clásico');
        $this->valorar($paquete, 4, 'Muy buena guía.', '2026-09-20 10:00:00');
        $this->valorar($paquete, 5, null, '2026-09-21 10:00:00');

        $this->get('/paquetes')
            ->assertSee('4,5')
            ->assertSee('2 valoraciones');
    }

    public function test_los_comentarios_van_del_mas_nuevo_al_mas_viejo(): void
    {
        $paquete = $this->crearPaquete('Camino Inca Clásico');
        $this->valorar($paquete, 3, 'Comentario de septiembre.', '2026-09-10 10:00:00');
        $this->valorar($paquete, 5, 'Comentario de octubre.', '2026-10-10 10:00:00');

        $this->get('/paquetes/'.$paquete->id_paquete.'/comentarios')
            ->assertSeeInOrder(['Comentario de octubre.', 'Comentario de septiembre.']);
    }

    // Las valoraciones sin comentario no se listan.
    public function test_no_lista_las_valoraciones_sin_comentario(): void
    {
        $paquete = $this->crearPaquete('Camino Inca Clásico');
        $this->valorar($paquete, 5, null, '2026-09-21 10:00:00');

        $this->get('/paquetes/'.$paquete->id_paquete.'/comentarios')
            ->assertSee('Todavía no hay comentarios');
    }

    public function test_filtra_los_comentarios_por_puntaje(): void
    {
        $paquete = $this->crearPaquete('Camino Inca Clásico');
        $this->valorar($paquete, 5, 'Excelente.', '2026-09-10 10:00:00');
        $this->valorar($paquete, 2, 'Regular.', '2026-09-11 10:00:00');

        $this->get('/paquetes/'.$paquete->id_paquete.'/comentarios?puntaje=5')
            ->assertSee('Excelente.')
            ->assertDontSee('Regular.');
    }

    public function test_un_puntaje_sin_comentarios_lo_informa(): void
    {
        $paquete = $this->crearPaquete('Camino Inca Clásico');
        $this->valorar($paquete, 5, 'Excelente.', '2026-09-10 10:00:00');

        $this->get('/paquetes/'.$paquete->id_paquete.'/comentarios?puntaje=1')
            ->assertSee('No hay comentarios con 1 estrellas');
    }

    // Un valor fuera de 1 a 5 se ignora y se muestran todos.
    public function test_un_puntaje_invalido_se_ignora(): void
    {
        $paquete = $this->crearPaquete('Camino Inca Clásico');
        $this->valorar($paquete, 5, 'Excelente.', '2026-09-10 10:00:00');
        $this->valorar($paquete, 2, 'Regular.', '2026-09-11 10:00:00');

        $this->get('/paquetes/'.$paquete->id_paquete.'/comentarios?puntaje=9')
            ->assertSee('Excelente.')
            ->assertSee('Regular.');
    }

    // Como el detalle del CU-06: un paquete dado de baja no tiene página pública.
    public function test_un_paquete_inactivo_redirige_al_listado(): void
    {
        $paquete = $this->crearPaquete('Camino Inca Clásico');
        $paquete->update(['estado' => EstadoPaquete::Inactivo]);

        $this->get('/paquetes/'.$paquete->id_paquete.'/comentarios')->assertRedirect('/paquetes');
    }

    private function crearPaquete(string $nombre): Paquete
    {
        return Paquete::create([
            'id_recorrido' => $this->recorrido->id_recorrido,
            'nombre' => $nombre,
            'precio_base' => 750,
            'costo_noche_extra_cusco' => 60,
            'costo_equipo_camping' => 45,
            'cantidad_porteadores' => 6,
            'fecha_creacion' => '2026-01-10',
        ]);
    }

    // Una reserva finalizada de una salida del paquete, con su valoración.
    private function valorar(Paquete $paquete, int $puntaje, ?string $comentario, string $fecha): Valoracion
    {
        // Una sola salida por paquete y fecha (la base lo exige): se reutiliza si ya existe.
        // whereDate: la columna guarda fecha y hora, así que se compara sólo el día.
        $excursion = Excursion::where('id_paquete', $paquete->id_paquete)
            ->whereDate('fecha_salida', '2026-09-07')
            ->first()
            ?? Excursion::create([
                'id_paquete' => $paquete->id_paquete,
                'id_guia' => $this->guia->id_usuario,
                'fecha_salida' => '2026-09-07',
                'cupo' => 12,
            ]);

        $this->reservasCreadas++;

        $reserva = Reserva::create([
            'id_excursion' => $excursion->id_excursion,
            'numero_reserva' => sprintf('000%03d-1', $this->reservasCreadas),
            'correo_electronico' => 'cliente'.$this->reservasCreadas.'@correo.com',
            'fecha_reserva' => '2026-03-02 10:00:00',
            'estado' => EstadoReserva::Finalizada,
            'estado_saldo' => EstadoSaldo::Abonado,
            'noches_extra_antes' => 0,
            'noches_extra_despues' => 0,
            'fecha_limite_saldo' => null,
            'fecha_limite_confirmacion' => '2026-07-03 23:59:59',
        ]);

        return Valoracion::create([
            'id_reserva' => $reserva->id_reserva,
            'fecha' => $fecha,
            'puntaje_experiencia_general' => $puntaje,
            'comentario' => $comentario,
        ]);
    }
}
