<?php

namespace Tests\Feature;

use App\Enums\EstadoPaquete;
use App\Enums\Rol;
use App\Jobs\LiberarCupoRetenido;
use App\Models\Excursion;
use App\Models\Guia;
use App\Models\Paquete;
use App\Models\Recorrido;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class PagarReservaTest extends TestCase
{
    use RefreshDatabase;

    private Excursion $excursion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-06 10:00:00'));

        // Un plazo fijo para la prueba, así no depende de RESERVA_MINUTOS_RETENCION en el .env de cada uno.
        config(['reserva.minutos_retencion' => 3]);

        // 5 plazas retenidas: 2 de la reserva en curso del cliente y 3 de otros. Así se nota si se descuenta de más.
        $this->excursion = $this->crearExcursion(plazasRetenidas: 5);
    }

    public function test_sin_reserva_en_curso_avisa_sin_error(): void
    {
        Livewire::test('pagar-reserva')
            ->assertSee('No tenés una reserva en curso.')
            ->assertSee('Ver los paquetes')
            ->assertDontSee('Pagar con Mercado Pago');
    }

    public function test_con_la_retencion_vigente_muestra_la_reserva_y_el_contador(): void
    {
        $this->guardarReservaEnCurso();

        Livewire::test('pagar-reserva')
            ->assertSee('Pago de la reserva')
            ->assertSee('Tus lugares están guardados')
            ->assertSee('Tenés 3 minutos para completar el pago. Si el plazo se vence, los lugares vuelven a estar disponibles.')
            ->assertSeeInOrder(['Retención:', '03:00'])
            ->assertSeeHtml('role="timer"')
            ->assertSee('Camino Inca Clásico')
            ->assertSee('18/01/2027')
            ->assertSee('Ana Pérez')
            ->assertSee('Bruno Gómez')
            ->assertSeeInOrder(['Llevan equipo de camping', '1 de 2'])
            ->assertSee('1 antes del recorrido')
            ->assertSeeHtmlInOrder(['disabled="disabled"', 'Pagar con Mercado Pago', 'Disponible próximamente']);
    }

    public function test_cancelar_libera_los_lugares_una_sola_vez(): void
    {
        $enCurso = $this->guardarReservaEnCurso();

        Livewire::test('pagar-reserva')
            ->call('cancelar')
            ->assertRedirect('/');

        $this->assertSame(3, $this->excursion->fresh()->plazas_retenidas);
        $this->assertNull(session('reserva_en_curso'));

        // Después llega la tarea demorada del vencimiento: no descuenta de nuevo.
        (new LiberarCupoRetenido($this->excursion->id_excursion, 2, $enCurso['id_retencion']))->handle();

        $this->assertSame(3, $this->excursion->fresh()->plazas_retenidas);
    }

    public function test_si_vencio_al_abrir_la_pantalla_libera_los_lugares(): void
    {
        $this->guardarReservaEnCurso();
        $this->travel(4)->minutes();

        Livewire::test('pagar-reserva')
            ->assertSee('Se venció el plazo')
            ->assertSee('Pasaron los 3 minutos sin que se registrara el pago y tus lugares se liberaron. Podés volver a reservar.')
            ->assertSee('Volver a reservar')
            ->assertSeeHtml('href="/reservar/'.$this->excursion->id_paquete.'/2027-01-18"')
            ->assertDontSee('Pagar con Mercado Pago');

        $this->assertSame(3, $this->excursion->fresh()->plazas_retenidas);
        $this->assertNull(session('reserva_en_curso'));
    }

    public function test_si_la_tarea_demorada_ya_libero_los_lugares_no_descuenta_de_nuevo(): void
    {
        $enCurso = $this->guardarReservaEnCurso();
        $this->travel(4)->minutes();

        // La cola ya corrió la tarea del vencimiento antes de que el cliente vuelva a la pantalla.
        (new LiberarCupoRetenido($this->excursion->id_excursion, 2, $enCurso['id_retencion']))->handle();

        Livewire::test('pagar-reserva')->assertSee('Se venció el plazo');

        $this->assertSame(3, $this->excursion->fresh()->plazas_retenidas);
    }

    public function test_si_vence_con_la_pantalla_abierta_libera_los_lugares_al_comprobar(): void
    {
        $this->guardarReservaEnCurso();
        $componente = Livewire::test('pagar-reserva')->assertSee('Tus lugares están guardados');

        $this->travel(3)->minutes();

        $componente->call('comprobarVencimiento')
            ->assertReturned(0)
            ->assertSee('Se venció el plazo')
            ->assertSee('Volver a reservar');

        $this->assertSame(3, $this->excursion->fresh()->plazas_retenidas);
        $this->assertNull(session('reserva_en_curso'));
    }

    public function test_antes_de_vencer_comprobar_no_libera_nada(): void
    {
        $this->guardarReservaEnCurso();
        $componente = Livewire::test('pagar-reserva');

        $this->travel(170)->seconds();

        // Al navegador le contesta los segundos que faltan, para que el contador siga desde ahí.
        $componente->call('comprobarVencimiento')
            ->assertReturned(10)
            ->assertSee('Tus lugares están guardados');

        $this->assertSame(5, $this->excursion->fresh()->plazas_retenidas);
        $this->assertNotNull(session('reserva_en_curso'));
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
