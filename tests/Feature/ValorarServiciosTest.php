<?php

namespace Tests\Feature;

use App\Enums\CategoriaValoracion;
use App\Enums\EstadoPermiso;
use App\Enums\EstadoReserva;
use App\Enums\EstadoSaldo;
use App\Enums\Rol;
use App\Models\Excursion;
use App\Models\Excursionista;
use App\Models\Guia;
use App\Models\Paquete;
use App\Models\Recorrido;
use App\Models\Reserva;
use App\Models\Usuario;
use App\Models\Valoracion;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// CU-23 Valorar Servicios (R-23), en el portal del cliente.
class ValorarServiciosTest extends TestCase
{
    use RefreshDatabase;

    private const CORREO = 'paula.benitez@mail.com';

    private const NUMERO = '000118-1';

    private const TODO_CINCO = [
        'Hotel' => 5, 'CampingDeEtapa' => 5, 'TransporteEnBus' => 5,
        'TransporteFerroviario' => 5, 'Porteadores' => 5, 'Guia' => 5,
    ];

    private Reserva $reserva;

    protected function setUp(): void
    {
        parent::setUp();

        $recorrido = Recorrido::create(['nombre' => 'Camino Inca de 4 días', 'duracion_dias' => 4, 'cantidad_campings' => 3]);
        $paquete = Paquete::create([
            'id_recorrido' => $recorrido->id_recorrido,
            'nombre' => 'Camino Inca Clásico',
            'precio_base' => 750,
            'costo_noche_extra_cusco' => 60,
            'costo_equipo_camping' => 45,
            'cantidad_porteadores' => 6,
            'fecha_creacion' => '2026-01-10',
        ]);
        $usuario = Usuario::create(['correo' => 'guia@caminodelinca.test', 'password' => 'guia1234', 'rol' => Rol::Guia]);
        $guia = Guia::create(['id_usuario' => $usuario->id_usuario, 'nombre' => 'Rosa', 'apellido' => 'Quispe']);
        $excursion = Excursion::create([
            'id_paquete' => $paquete->id_paquete,
            'id_guia' => $guia->id_usuario,
            'fecha_salida' => '2026-09-07',
            'cupo' => 12,
        ]);

        $this->reserva = Reserva::create([
            'id_excursion' => $excursion->id_excursion,
            'numero_reserva' => self::NUMERO,
            'correo_electronico' => self::CORREO,
            'fecha_reserva' => '2026-03-02 10:00:00',
            'estado' => EstadoReserva::Finalizada,
            'estado_saldo' => EstadoSaldo::Abonado,
            'noches_extra_antes' => 0,
            'noches_extra_despues' => 0,
            'fecha_limite_saldo' => null,
            'fecha_limite_confirmacion' => '2026-07-03 23:59:59',
        ]);

        $this->crearIntegrante('Paula', 'BEN123', equipoCamping: false);
        $this->crearIntegrante('Martín', 'BEN456', equipoCamping: false);

        // Como queda la sesión cuando el cliente consulta su reserva en Mi reserva (CU-18).
        session(['acceso_reserva' => ['correo' => self::CORREO, 'numeroReserva' => self::NUMERO]]);
    }

    public function test_muestra_las_categorias_sin_equipo_de_camping_si_nadie_lo_llevo(): void
    {
        $this->pantalla()
            ->assertSee(['Hotel', 'Camping de Etapa', 'Transporte en Bus', 'Transporte Ferroviario', 'Porteadores', 'Guía'])
            ->assertSee('Experiencia general (promedio)')
            ->assertDontSee('Equipo de Camping');
    }

    // R-23: la experiencia general ya no se elige; se muestra el promedio mientras se completan las categorías.
    public function test_el_formulario_no_pide_la_experiencia_general(): void
    {
        $pantalla = $this->pantalla()
            ->assertDontSeeHtml('wire:model.live="experienciaGeneral"')
            ->assertDontSee('El recorrido completo')
            ->assertSee('Se calcula cuando valores todos los servicios.');

        $this->completar($pantalla)
            ->assertDontSee('Se calcula cuando valores todos los servicios.')
            ->assertSeeHtml('aria-label="4 de 5 estrellas"');
    }

    public function test_incluye_el_equipo_de_camping_si_algun_integrante_lo_llevo(): void
    {
        $this->crearIntegrante('Lucía', 'BEN789', equipoCamping: true);

        $this->pantalla()->assertSee('Equipo de Camping');
    }

    public function test_registra_la_valoracion_con_un_puntaje_por_categoria(): void
    {
        $this->completar($this->pantalla())
            ->set('comentario', 'El guía fue excelente.')
            ->call('enviar')
            ->assertHasNoErrors()
            ->assertSee('¡Gracias por tu valoración!');

        $valoracion = $this->reserva->refresh()->valoracion;
        // 5 + 4 + 3 + 5 + 5 + 4 = 26, entre 6 categorías: 4,33 → 4.
        $this->assertSame(4, (int) $valoracion->puntaje_experiencia_general);
        $this->assertSame('El guía fue excelente.', $valoracion->comentario);
        $this->assertCount(6, $valoracion->detalles);
        $this->assertSame(4, (int) $valoracion->detalles->firstWhere('categoria', CategoriaValoracion::Guia)->puntaje);
    }

    public function test_el_comentario_es_opcional(): void
    {
        $this->completar($this->pantalla())
            ->call('enviar')
            ->assertHasNoErrors();

        $this->assertNull($this->reserva->refresh()->valoracion->comentario);
    }

    public function test_pide_completar_todos_los_puntajes(): void
    {
        $this->pantalla()
            ->set('puntajes.Hotel', 4)
            ->call('enviar')
            ->assertHasErrors(['puntajes.Guia' => 'required']);

        $this->assertFalse($this->reserva->tieneValoracion());
    }

    public function test_el_puntaje_va_de_1_a_5_estrellas(): void
    {
        $this->completar($this->pantalla())
            ->set('puntajes.Hotel', 6)
            ->call('enviar')
            ->assertHasErrors(['puntajes.Hotel' => 'between']);
    }

    public function test_una_reserva_ya_valorada_no_muestra_el_formulario(): void
    {
        Valoracion::create(['id_reserva' => $this->reserva->id_reserva, 'fecha' => '2026-09-14 19:30:00', 'puntaje_experiencia_general' => 4]);

        $this->pantalla()
            ->assertSee('Ya valoraste los servicios de este viaje.')
            ->assertDontSee('Enviar valoración');
    }

    public function test_una_reserva_sin_finalizar_no_se_valora(): void
    {
        $this->reserva->update(['estado' => EstadoReserva::Confirmada]);

        $this->pantalla()
            ->assertSee('No se puede valorar esta reserva')
            ->assertDontSee('Enviar valoración');
    }

    // Si se valoró desde otra pestaña mientras tanto, el modelo lo rechaza al guardar.
    public function test_no_se_valora_dos_veces(): void
    {
        $pantalla = $this->completar($this->pantalla());

        Valoracion::create(['id_reserva' => $this->reserva->id_reserva, 'fecha' => '2026-09-14 19:30:00', 'puntaje_experiencia_general' => 4]);

        $pantalla->call('enviar')->assertHasErrors('envio');

        $this->assertSame(1, Valoracion::where('id_reserva', $this->reserva->id_reserva)->count());
    }

    public function test_el_modelo_exige_todas_las_categorias(): void
    {
        $this->expectException(DomainException::class);

        Valoracion::valorarServicios($this->reserva, ['Hotel' => 5], null);
    }

    /* ------------------- Experiencia general: promedio de las categorías (R-23) ------------------- */

    public function test_el_promedio_se_redondea_al_entero_mas_cercano(): void
    {
        $this->assertSame(4, Valoracion::calcularExperienciaGeneral([5, 4, 4, 4, 5]));    // 4,4 → 4
        $this->assertSame(5, Valoracion::calcularExperienciaGeneral([5, 5, 4, 4, 5, 4])); // 4,5 → 5
        $this->assertSame(4, Valoracion::calcularExperienciaGeneral([5, 4, 3, 5, 5, 4])); // 4,33 → 4
        $this->assertSame(2, Valoracion::calcularExperienciaGeneral([1, 2]));             // 1,5 → 2
        $this->assertSame(3, Valoracion::calcularExperienciaGeneral([3, 3, 3, 3, 3, 3, 3]));
    }

    public function test_un_promedio_de_4_5_se_guarda_como_5(): void
    {
        $valoracion = Valoracion::valorarServicios($this->reserva, [
            'Hotel' => 5, 'CampingDeEtapa' => 5, 'TransporteEnBus' => 4,
            'TransporteFerroviario' => 4, 'Porteadores' => 5, 'Guia' => 4,
        ], null);

        $this->assertSame(5, (int) $valoracion->puntaje_experiencia_general);
    }

    // Si alguien llevó equipo de camping, su puntaje entra en el promedio: (6 × 5 + 1) / 7 = 4,43 → 4.
    public function test_con_equipo_de_camping_su_puntaje_cuenta_en_el_promedio(): void
    {
        $this->crearIntegrante('Lucía', 'BEN789', equipoCamping: true);

        $valoracion = Valoracion::valorarServicios($this->reserva, [...self::TODO_CINCO, 'EquipoDeCamping' => 1], null);

        $this->assertSame(4, (int) $valoracion->puntaje_experiencia_general);
        $this->assertCount(7, $valoracion->detalles);
    }

    // Si nadie llevó equipo de camping, un puntaje de equipo que llegue igual no se guarda ni cuenta.
    public function test_sin_equipo_de_camping_no_cuenta_en_el_promedio(): void
    {
        $valoracion = Valoracion::valorarServicios($this->reserva, [...self::TODO_CINCO, 'EquipoDeCamping' => 1], null);

        $this->assertSame(5, (int) $valoracion->puntaje_experiencia_general);
        $this->assertCount(6, $valoracion->detalles);
    }

    public function test_sin_consultar_la_reserva_vuelve_a_mi_reserva(): void
    {
        session()->forget('acceso_reserva');

        $this->pantalla()->assertRedirect('/mi-reserva');
    }

    public function test_con_otro_correo_vuelve_a_mi_reserva(): void
    {
        session(['acceso_reserva' => ['correo' => 'otra.persona@mail.com', 'numeroReserva' => self::NUMERO]]);

        $this->pantalla()->assertRedirect('/mi-reserva');
    }

    private function pantalla()
    {
        return Livewire::test('valorar-servicios', ['numeroReserva' => self::NUMERO]);
    }

    // Un puntaje para cada una de las seis categorías que se valoran sin equipo de camping.
    private function completar($pantalla)
    {
        return $pantalla
            ->set('puntajes.Hotel', 5)
            ->set('puntajes.CampingDeEtapa', 4)
            ->set('puntajes.TransporteEnBus', 3)
            ->set('puntajes.TransporteFerroviario', 5)
            ->set('puntajes.Porteadores', 5)
            ->set('puntajes.Guia', 4);
    }

    private function crearIntegrante(string $nombre, string $documento, bool $equipoCamping): void
    {
        Excursionista::create([
            'id_reserva' => $this->reserva->id_reserva,
            'nombre' => $nombre,
            'apellido' => 'Benítez',
            'documento_pasaporte' => $documento,
            'equipo_camping' => $equipoCamping,
            'estado_permiso' => EstadoPermiso::Obtenido,
        ]);
    }
}
