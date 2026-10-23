<?php

namespace Tests\Feature;

use App\Enums\EstadoPaquete;
use App\Enums\EstadoReserva;
use App\Enums\EstadoSaldo;
use App\Enums\Rol;
use App\Enums\TipoServicio;
use App\Models\Etapa;
use App\Models\Excursion;
use App\Models\Guia;
use App\Models\Paquete;
use App\Models\Recorrido;
use App\Models\Reserva;
use App\Models\Servicio;
use App\Models\Usuario;
use App\Models\Valoracion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// CU-06 Consultar Paquete: curso normal (pasos 1 a 4), flujos alternativos A1 y A2, el promedio de valoraciones y los
// precios con centavos y el límite de tres meses compartido con CU-14.
class ConsultarPaqueteTest extends TestCase
{
    use RefreshDatabase;

    private Recorrido $recorrido;

    private Guia $guia;

    private Paquete $paquete;

    protected function setUp(): void
    {
        parent::setUp();

        // Fecha fija, para que las salidas «futuras» lo sigan siendo cuando se corra la prueba más adelante.
        Carbon::setTestNow('2026-10-06');

        $this->recorrido = Recorrido::create([
            'nombre' => 'Camino Inca de 4 días',
            'duracion_dias' => 4,
            'cantidad_campings' => 3,
        ]);

        foreach (['Km 82', 'Wayllabamba', 'Pacaymayo'] as $orden => $nombre) {
            Etapa::create(['id_recorrido' => $this->recorrido->id_recorrido, 'nombre' => $nombre, 'orden' => $orden + 1]);
        }

        $usuario = Usuario::create([
            'correo' => 'guia@caminodelinca.test',
            'password' => 'guia1234',
            'rol' => Rol::Guia,
        ]);

        $this->guia = Guia::create(['id_usuario' => $usuario->id_usuario, 'nombre' => 'Rosa', 'apellido' => 'Quispe']);

        $this->paquete = $this->crearPaquete('Camino Inca Clásico');

        $hotel = Servicio::create(['tipo' => TipoServicio::Hotel, 'nombre' => 'Hotel en Cusco']);
        $tren = Servicio::create(['tipo' => TipoServicio::TransporteFerroviario, 'nombre' => 'Tren a Ollantaytambo']);
        $this->paquete->servicios()->attach([$hotel->id_servicio, $tren->id_servicio]);
    }

    public function test_muestra_el_detalle_completo_del_paquete(): void
    {
        $respuesta = $this->get('/paquetes/'.$this->paquete->id_paquete);

        $respuesta->assertOk();
        $respuesta->assertSee('Camino Inca Clásico');
        $respuesta->assertSee('Camino Inca de 4 días · 3 etapas');
        $respuesta->assertSee('Hotel en Cusco');
        $respuesta->assertSee('Tren a Ollantaytambo');
        $respuesta->assertSee('6 porteadores');
        $respuesta->assertSeeInOrder(['Precio base, por persona', 'USD 750,00']);
        $respuesta->assertSeeInOrder(['Noche extra en Cusco, por persona', 'USD 60,00']);
        $respuesta->assertSeeInOrder(['Equipo de camping (opcional), por equipo', 'USD 45,00']);
    }

    public function test_muestra_las_salidas_disponibles_con_su_cupo_y_su_guia(): void
    {
        $this->crearExcursion($this->paquete, '2026-09-07');
        $this->crearExcursion($this->paquete, '2026-12-14');
        $this->crearExcursion($this->paquete, '2027-02-15', plazasRetenidas: 4);
        $this->crearExcursion($this->paquete, '2027-02-01', plazasRetenidas: 12);

        $respuesta = $this->get('/paquetes/'.$this->paquete->id_paquete);

        $respuesta->assertSee('15/02/2027');
        $respuesta->assertSee('Quedan 8 lugares');
        $respuesta->assertSee('Guía: Rosa Quispe');
        $respuesta->assertDontSee('07/09/2026');
        $respuesta->assertDontSee('14/12/2026');
        $respuesta->assertDontSee('01/02/2027');
    }

    // Flujo alternativo A2.
    public function test_cada_salida_lleva_al_formulario_de_reserva(): void
    {
        $this->crearExcursion($this->paquete, '2027-02-15');

        $this->get('/paquetes/'.$this->paquete->id_paquete)
            ->assertSee('href="/reservar/'.$this->paquete->id_paquete.'/2027-02-15"', false);
    }

    // El límite se compara por día, también cuando el cliente consulta de noche.
    public function test_el_detalle_y_cu14_comparten_el_limite_de_tres_meses(): void
    {
        $this->travelTo(Carbon::parse('2026-10-25 23:59:00'));
        $this->crearExcursion($this->paquete, '2027-01-25');
        $this->crearExcursion($this->paquete, '2027-02-01');

        $this->get('/paquetes/'.$this->paquete->id_paquete)
            ->assertSee('25/01/2027')
            ->assertSee('01/02/2027');

        $this->get('/reservar/'.$this->paquete->id_paquete.'/2027-01-25')
            ->assertOk()
            ->assertSee('Titular de la reserva');

        $this->travelTo(Carbon::parse('2026-10-26 00:01:00'));

        $this->get('/paquetes/'.$this->paquete->id_paquete)
            ->assertDontSee('25/01/2027')
            ->assertSee('01/02/2027');

        $this->get('/paquetes')
            ->assertDontSee('25/01/2027')
            ->assertSee('01/02/2027');

        Livewire::test('listar-paquetes')
            ->set('criterio', '25/01/2027')
            ->call('buscarPaquetes')
            ->assertHasNoErrors()
            ->assertSee('No encontramos paquetes');

        $this->get('/reservar/'.$this->paquete->id_paquete.'/2027-01-25')
            ->assertSee('Esta salida ya no se puede reservar')
            ->assertDontSee('Titular de la reserva');
    }

    public function test_el_limite_de_tres_meses_no_se_desborda_al_mes_siguiente(): void
    {
        // El 30/11 más tres meses es el 28/02. La salida del lunes 01/03 ya se puede reservar.
        $this->travelTo(Carbon::parse('2026-11-30 12:00:00'));
        $this->crearExcursion($this->paquete, '2027-02-22');
        $this->crearExcursion($this->paquete, '2027-03-01');

        $this->get('/paquetes/'.$this->paquete->id_paquete)
            ->assertDontSee('22/02/2027')
            ->assertSee('01/03/2027');

        $this->get('/reservar/'.$this->paquete->id_paquete.'/2027-03-01')
            ->assertOk()
            ->assertSee('Titular de la reserva');
    }

    public function test_calcula_el_promedio_con_las_valoraciones_de_sus_reservas(): void
    {
        $salidaRealizada = $this->crearExcursion($this->paquete, '2026-08-03');
        $this->valorar($salidaRealizada, '000201-1', 4);
        $this->valorar($salidaRealizada, '000202-2', 5);

        // Las valoraciones de otro paquete no cuentan.
        $otro = $this->crearPaquete('Camino Inca Corto');
        $this->valorar($this->crearExcursion($otro, '2026-08-10'), '000203-3', 1);

        $this->get('/paquetes/'.$this->paquete->id_paquete)->assertSee('4,5');
    }

    public function test_avisa_si_todavia_no_tiene_valoraciones(): void
    {
        $this->get('/paquetes/'.$this->paquete->id_paquete)->assertSee('Todavía no tiene valoraciones.');
    }

    // Revisión del PR: los precios con centavos se muestran con sus dos decimales.
    public function test_muestra_los_precios_con_centavos(): void
    {
        $this->paquete->update([
            'precio_base' => 750.49,
            'costo_noche_extra_cusco' => 60.75,
            'costo_equipo_camping' => 45.25,
        ]);

        $this->get('/paquetes/'.$this->paquete->id_paquete)
            ->assertSee('USD 750,49')
            ->assertSee('USD 60,75')
            ->assertSee('USD 45,25');
    }

    // Flujo alternativo A1: se informa y se redirige al listado.
    public function test_un_paquete_inactivo_redirige_al_listado_con_el_aviso(): void
    {
        $inactivo = $this->crearPaquete('Paquete dado de baja', EstadoPaquete::Inactivo);

        $this->get('/paquetes/'.$inactivo->id_paquete)->assertRedirect('/paquetes');

        $this->followingRedirects()
            ->get('/paquetes/'.$inactivo->id_paquete)
            ->assertSee('Ese paquete no está disponible actualmente')
            ->assertDontSee('Paquete dado de baja');
    }

    public function test_un_paquete_que_no_existe_redirige_al_listado_con_el_aviso(): void
    {
        $this->get('/paquetes/999999')->assertRedirect('/paquetes');

        $this->followingRedirects()
            ->get('/paquetes/999999')
            ->assertSee('Ese paquete no está disponible actualmente');
    }

    public function test_el_listado_no_muestra_el_aviso_si_no_hubo_redireccion(): void
    {
        $this->get('/paquetes')->assertDontSee('Ese paquete no está disponible actualmente');
    }

    private function crearPaquete(string $nombre, EstadoPaquete $estado = EstadoPaquete::Activo): Paquete
    {
        return Paquete::create([
            'id_recorrido' => $this->recorrido->id_recorrido,
            'nombre' => $nombre,
            'precio_base' => 750,
            'costo_noche_extra_cusco' => 60,
            'costo_equipo_camping' => 45,
            'cantidad_porteadores' => 6,
            'estado' => $estado,
            'fecha_creacion' => '2026-10-01',
        ]);
    }

    private function crearExcursion(Paquete $paquete, string $fechaSalida, int $plazasRetenidas = 0): Excursion
    {
        return Excursion::create([
            'id_paquete' => $paquete->id_paquete,
            'id_guia' => $this->guia->id_usuario,
            'fecha_salida' => $fechaSalida,
            'cupo' => 12,
            'plazas_retenidas' => $plazasRetenidas,
        ]);
    }

    // Una reserva finalizada de esa salida, con su valoración de experiencia general.
    private function valorar(Excursion $excursion, string $numeroReserva, int $puntaje): void
    {
        $reserva = Reserva::create([
            'id_excursion' => $excursion->id_excursion,
            'numero_reserva' => $numeroReserva,
            'correo_electronico' => 'cliente@correo.com',
            'fecha_reserva' => '2026-03-02 10:00:00',
            'estado' => EstadoReserva::Finalizada,
            'estado_saldo' => EstadoSaldo::Abonado,
            'noches_extra_antes' => 0,
            'noches_extra_despues' => 0,
            'fecha_limite_saldo' => null,
            'fecha_limite_confirmacion' => '2026-07-03 23:59:59',
        ]);

        Valoracion::create([
            'id_reserva' => $reserva->id_reserva,
            'fecha' => '2026-08-12 18:00:00',
            'puntaje_experiencia_general' => $puntaje,
            'comentario' => null,
        ]);
    }
}
