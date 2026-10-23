<?php

namespace Tests\Feature;

use App\Enums\CategoriaValoracion;
use App\Enums\EstadoPermiso;
use App\Enums\EstadoReserva;
use App\Enums\EstadoSaldo;
use App\Enums\Rol;
use App\Enums\TipoServicio;
use App\Filament\Resources\Valoraciones\Pages\ListValoraciones;
use App\Filament\Resources\Valoraciones\Pages\ViewValoracion;
use App\Models\Excursion;
use App\Models\Excursionista;
use App\Models\Guia;
use App\Models\Paquete;
use App\Models\Recorrido;
use App\Models\Reserva;
use App\Models\Servicio;
use App\Models\Usuario;
use App\Models\Valoracion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// CU-08 Listar Valoraciones y CU-09 Consultar Valoración (R-08 y R-09), en el panel del administrador.
class ValoracionesPanelTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $administrador;

    private Usuario $usuarioGuia;

    private Guia $rosa;

    private Guia $luis;

    private Servicio $hotelCusco;

    private Valoracion $delClasico;

    private Valoracion $delCorto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->administrador = Usuario::create(['correo' => 'admin@caminodelinca.test', 'password' => 'admin1234', 'rol' => Rol::Administrador]);
        $this->usuarioGuia = Usuario::create(['correo' => 'rosa@caminodelinca.test', 'password' => 'guia1234', 'rol' => Rol::Guia]);
        $this->rosa = Guia::create(['id_usuario' => $this->usuarioGuia->id_usuario, 'nombre' => 'Rosa', 'apellido' => 'Quispe']);
        $luis = Usuario::create(['correo' => 'luis@caminodelinca.test', 'password' => 'guia1234', 'rol' => Rol::Guia]);
        $this->luis = Guia::create(['id_usuario' => $luis->id_usuario, 'nombre' => 'Luis', 'apellido' => 'Mamani']);

        $recorrido = Recorrido::create(['nombre' => 'Camino Inca de 4 días', 'duracion_dias' => 4, 'cantidad_campings' => 3]);
        $this->hotelCusco = Servicio::create(['tipo' => TipoServicio::Hotel, 'nombre' => 'Hotel en Cusco']);
        $hotelAguas = Servicio::create(['tipo' => TipoServicio::Hotel, 'nombre' => 'Hotel en Aguas Calientes']);

        $clasico = $this->crearPaquete($recorrido, 'Camino Inca Clásico', porteadores: 6);
        $clasico->servicios()->attach($this->hotelCusco->id_servicio);
        $corto = $this->crearPaquete($recorrido, 'Camino Inca Corto', porteadores: 0);
        $corto->servicios()->attach($hotelAguas->id_servicio);

        $this->delClasico = $this->valorar(
            $this->crearReserva($clasico, $this->rosa, '000118-1', 'paula.benitez@mail.com', equipoCamping: true),
            experienciaGeneral: 5,
            comentario: 'El guía fue excelente.',
            puntajes: ['Guia' => 5, 'Hotel' => 4, 'EquipoDeCamping' => 3],
        );

        $this->delCorto = $this->valorar(
            $this->crearReserva($corto, $this->luis, '000119-3', 'carlos.gomez@mail.com', equipoCamping: false),
            experienciaGeneral: 2,
            comentario: null,
            puntajes: ['Guia' => 1, 'Hotel' => 3],
        );
    }

    /* ---------------------------------- CU-08 ---------------------------------- */

    public function test_sin_filtros_muestra_todas_las_valoraciones(): void
    {
        $this->listado()->assertCanSeeTableRecords([$this->delClasico, $this->delCorto]);
    }

    public function test_filtra_por_paquete(): void
    {
        $this->listado()
            ->filterTable('paquete', $this->delClasico->reserva->excursion->id_paquete)
            ->assertCanSeeTableRecords([$this->delClasico])
            ->assertCanNotSeeTableRecords([$this->delCorto]);
    }

    public function test_filtra_por_hotel(): void
    {
        $this->listado()
            ->filterTable('servicio_hotel', $this->hotelCusco->id_servicio)
            ->assertCanSeeTableRecords([$this->delClasico])
            ->assertCanNotSeeTableRecords([$this->delCorto]);
    }

    public function test_filtra_por_guia(): void
    {
        $this->listado()
            ->filterTable('guia', $this->luis->id_usuario)
            ->assertCanSeeTableRecords([$this->delCorto])
            ->assertCanNotSeeTableRecords([$this->delClasico]);
    }

    public function test_filtra_las_que_tienen_porteadores(): void
    {
        $this->listado()
            ->filterTable('con_porteadores')
            ->assertCanSeeTableRecords([$this->delClasico])
            ->assertCanNotSeeTableRecords([$this->delCorto]);
    }

    public function test_filtra_las_que_valoran_el_equipo_de_camping(): void
    {
        $this->listado()
            ->filterTable('con_equipo_camping')
            ->assertCanSeeTableRecords([$this->delClasico])
            ->assertCanNotSeeTableRecords([$this->delCorto]);
    }

    public function test_filtra_por_el_puntaje_de_una_categoria(): void
    {
        $this->listado()
            ->filterTable('puntaje', ['categoria' => 'Guia', 'puntaje' => '1'])
            ->assertCanSeeTableRecords([$this->delCorto])
            ->assertCanNotSeeTableRecords([$this->delClasico]);
    }

    public function test_filtra_por_el_puntaje_de_la_experiencia_general(): void
    {
        $this->listado()
            ->filterTable('puntaje', ['categoria' => 'experiencia_general', 'puntaje' => '5'])
            ->assertCanSeeTableRecords([$this->delClasico])
            ->assertCanNotSeeTableRecords([$this->delCorto]);
    }

    public function test_busca_por_numero_de_reserva_o_comentario(): void
    {
        $this->listado()
            ->searchTable('000119-3')
            ->assertCanSeeTableRecords([$this->delCorto])
            ->assertCanNotSeeTableRecords([$this->delClasico]);

        $this->listado()
            ->searchTable('excelente')
            ->assertCanSeeTableRecords([$this->delClasico])
            ->assertCanNotSeeTableRecords([$this->delCorto]);
    }

    /* ---------------------------------- CU-09 ---------------------------------- */

    public function test_el_detalle_muestra_los_puntajes_el_comentario_y_la_reserva(): void
    {
        $this->actingAs($this->administrador);

        Livewire::test(ViewValoracion::class, ['record' => $this->delClasico->getRouteKey()])
            ->assertSee('El guía fue excelente.')
            ->assertSee('5 de 5')
            ->assertSee(['Hotel: ★★★★☆ 4 de 5', 'Guía: ★★★★★ 5 de 5', 'Equipo de Camping: ★★★☆☆ 3 de 5'])
            ->assertSee(['000118-1', 'paula.benitez@mail.com', 'Camino Inca Clásico', 'Rosa Quispe']);
    }

    public function test_sin_comentario_lo_aclara(): void
    {
        $this->actingAs($this->administrador);

        Livewire::test(ViewValoracion::class, ['record' => $this->delCorto->getRouteKey()])
            ->assertSee('Sin comentario');
    }

    public function test_un_guia_no_entra_a_las_valoraciones(): void
    {
        $this->actingAs($this->usuarioGuia);

        $this->get('/admin/valoraciones')->assertForbidden();
        $this->get('/admin/valoraciones/'.$this->delClasico->id_valoracion)->assertForbidden();
    }

    private function listado()
    {
        $this->actingAs($this->administrador);

        return Livewire::test(ListValoraciones::class);
    }

    private function crearPaquete(Recorrido $recorrido, string $nombre, int $porteadores): Paquete
    {
        return Paquete::create([
            'id_recorrido' => $recorrido->id_recorrido,
            'nombre' => $nombre,
            'precio_base' => 750,
            'costo_noche_extra_cusco' => 60,
            'costo_equipo_camping' => 45,
            'cantidad_porteadores' => $porteadores,
            'fecha_creacion' => '2026-01-10',
        ]);
    }

    private function crearReserva(Paquete $paquete, Guia $guia, string $numero, string $correo, bool $equipoCamping): Reserva
    {
        $excursion = Excursion::create([
            'id_paquete' => $paquete->id_paquete,
            'id_guia' => $guia->id_usuario,
            'fecha_salida' => '2026-09-07',
            'cupo' => 12,
        ]);

        $reserva = Reserva::create([
            'id_excursion' => $excursion->id_excursion,
            'numero_reserva' => $numero,
            'correo_electronico' => $correo,
            'fecha_reserva' => '2026-03-02 10:00:00',
            'estado' => EstadoReserva::Finalizada,
            'estado_saldo' => EstadoSaldo::Abonado,
            'noches_extra_antes' => 0,
            'noches_extra_despues' => 0,
            'fecha_limite_saldo' => null,
            'fecha_limite_confirmacion' => '2026-07-03 23:59:59',
        ]);

        Excursionista::create([
            'id_reserva' => $reserva->id_reserva,
            'nombre' => 'Titular',
            'apellido' => 'De prueba',
            'documento_pasaporte' => 'DOC'.substr($numero, 0, 6),
            'equipo_camping' => $equipoCamping,
            'estado_permiso' => EstadoPermiso::Obtenido,
        ]);

        return $reserva;
    }

    // Los detalles se crean directo, con los puntajes indicados (nombre del caso del enum => puntaje).
    private function valorar(Reserva $reserva, int $experienciaGeneral, ?string $comentario, array $puntajes): Valoracion
    {
        $valoracion = Valoracion::create([
            'id_reserva' => $reserva->id_reserva,
            'fecha' => '2026-09-14 19:30:00',
            'puntaje_experiencia_general' => $experienciaGeneral,
            'comentario' => $comentario,
        ]);

        foreach ($puntajes as $nombre => $puntaje) {
            $categoria = collect(CategoriaValoracion::cases())->first(fn (CategoriaValoracion $c) => $c->name === $nombre);
            $valoracion->detalles()->create(['categoria' => $categoria, 'puntaje' => $puntaje]);
        }

        return $valoracion;
    }
}
