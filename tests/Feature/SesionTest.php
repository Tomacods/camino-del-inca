<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Filament\Pages\Auth\IniciarSesion;
use App\Models\Guia;
use App\Models\Usuario;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// CU-24 Iniciar Sesión y CU-25 Cerrar Sesión (R-24 y R-25): el administrador y el guía entran al panel con su correo y
// su contraseña, y cada uno ve sólo lo de su rol.
class SesionTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $administrador;

    private Usuario $guia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->administrador = Usuario::create(['correo' => 'admin@caminodelinca.test', 'password' => 'admin1234', 'rol' => Rol::Administrador]);
        $this->guia = Usuario::create(['correo' => 'guia@caminodelinca.test', 'password' => 'guia1234', 'rol' => Rol::Guia]);
        Guia::create(['id_usuario' => $this->guia->id_usuario, 'nombre' => 'Rosa', 'apellido' => 'Quispe']);
    }

    /* ---------------------------------- CU-24 ---------------------------------- */

    public function test_el_administrador_inicia_sesion_con_su_correo_y_su_contrasena(): void
    {
        $this->iniciarSesion('admin@caminodelinca.test', 'admin1234')
            ->assertHasNoFormErrors()
            ->assertRedirect(Filament::getUrl());

        $this->assertAuthenticatedAs($this->administrador);
    }

    public function test_el_guia_inicia_sesion_con_su_correo_y_su_contrasena(): void
    {
        $this->iniciarSesion('guia@caminodelinca.test', 'guia1234')
            ->assertHasNoFormErrors()
            ->assertRedirect(Filament::getUrl());

        $this->assertAuthenticatedAs($this->guia);
    }

    // R-24: con credenciales incorrectas se rechaza el acceso y se informa el error.
    public function test_rechaza_una_contrasena_incorrecta(): void
    {
        $this->iniciarSesion('admin@caminodelinca.test', 'otra-clave')
            ->assertHasFormErrors(['correo']);

        $this->assertGuest();
    }

    public function test_rechaza_un_correo_que_no_esta_registrado(): void
    {
        $this->iniciarSesion('nadie@caminodelinca.test', 'admin1234')
            ->assertHasFormErrors(['correo']);

        $this->assertGuest();
    }

    public function test_pide_el_correo_y_la_contrasena(): void
    {
        $this->iniciarSesion('', '')
            ->assertHasFormErrors(['correo' => 'required', 'password' => 'required']);

        $this->assertGuest();
    }

    public function test_sin_sesion_el_panel_pide_iniciarla(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/reservas')->assertRedirect('/admin/login');
    }

    // R-24: el acceso a las funcionalidades depende del rol.
    public function test_el_administrador_ve_las_reservas_y_no_las_excursiones_del_guia(): void
    {
        $this->actingAs($this->administrador);

        $this->get('/admin/reservas')->assertOk();
        $this->get('/admin/excursiones')->assertForbidden();
    }

    public function test_el_guia_ve_sus_excursiones_y_no_las_reservas(): void
    {
        $this->actingAs($this->guia);

        $this->get('/admin/excursiones')->assertOk();
        $this->get('/admin/reservas')->assertForbidden();
    }

    // Si antes de iniciar sesión se quiso abrir una página, al entrar se va a esa página (comportamiento de Laravel).
    public function test_al_entrar_va_a_la_pagina_que_se_quiso_abrir_si_es_de_su_rol(): void
    {
        $this->get('/admin/excursiones')->assertRedirect('/admin/login');

        $this->iniciarSesion('guia@caminodelinca.test', 'guia1234')
            ->assertRedirect(url('/admin/excursiones'));
    }

    // Prueba manual 6 del 22/10: el guía que quiso abrir Reservas antes de entrar veía un 403 al iniciar sesión.
    public function test_al_entrar_descarta_la_pagina_que_se_quiso_abrir_si_es_de_otro_rol(): void
    {
        $this->get('/admin/reservas')->assertRedirect('/admin/login');

        $this->iniciarSesion('guia@caminodelinca.test', 'guia1234')
            ->assertRedirect(Filament::getUrl());

        $this->assertAuthenticatedAs($this->guia);
        $this->assertNull(session('url.intended'));
    }

    public function test_al_entrar_el_administrador_descarta_las_excursiones_del_guia(): void
    {
        $this->get('/admin/excursiones')->assertRedirect('/admin/login');

        $this->iniciarSesion('admin@caminodelinca.test', 'admin1234')
            ->assertRedirect(Filament::getUrl());
    }

    /* ---------------------------------- CU-25 ---------------------------------- */

    public function test_cierra_la_sesion_y_el_panel_vuelve_a_pedirla(): void
    {
        $this->actingAs($this->administrador)
            ->post(route('filament.admin.auth.logout'))
            ->assertRedirect();

        $this->assertGuest();

        $this->get('/admin')->assertRedirect('/admin/login');
    }

    private function iniciarSesion(string $correo, string $contrasena)
    {
        return Livewire::test(IniciarSesion::class)
            ->fillForm(['correo' => $correo, 'password' => $contrasena])
            ->call('authenticate');
    }
}
