<?php

namespace Tests\Feature;

use App\Enums\Rol;
use App\Filament\Pages\Auth\IniciarSesion;
use App\Models\Guia;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Acceso del personal: el enlace en el pie del portal y el inicio de sesión que lleva a la sección de cada rol.
class AccesoPersonalTest extends TestCase
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

    public function test_el_pie_del_portal_tiene_el_acceso_del_personal(): void
    {
        $this->get('/paquetes')
            ->assertSee('Acceso del personal')
            ->assertSee('href="/admin/login"', false);
    }

    public function test_el_administrador_inicia_sesion_y_va_a_paquetes(): void
    {
        $this->iniciarSesion('admin@caminodelinca.test', 'admin1234')
            ->assertRedirect('/admin/paquetes');
    }

    public function test_el_guia_inicia_sesion_y_va_a_sus_excursiones(): void
    {
        $this->iniciarSesion('guia@caminodelinca.test', 'guia1234')
            ->assertRedirect('/admin/excursiones');
    }

    // Si se pidió una página antes de entrar y el rol la ve, se respeta esa página.
    public function test_respeta_la_pagina_pedida_antes_de_entrar(): void
    {
        $this->get('/admin/reservas')->assertRedirect('/admin/login');

        $this->iniciarSesion('admin@caminodelinca.test', 'admin1234')
            ->assertRedirect('/admin/reservas');
    }

    public function test_el_panel_tiene_un_enlace_para_volver_al_portal(): void
    {
        $this->actingAs($this->administrador)
            ->get('/admin/paquetes')
            ->assertSee('Ver el portal')
            ->assertSee('href="/paquetes"', false);
    }

    private function iniciarSesion(string $correo, string $contrasena)
    {
        return Livewire::test(IniciarSesion::class)
            ->fillForm(['correo' => $correo, 'password' => $contrasena])
            ->call('authenticate');
    }
}
