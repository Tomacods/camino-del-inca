<?php

namespace Tests\Feature;

use Tests\TestCase;

class PortalInicioTest extends TestCase
{
    public function test_la_pagina_de_inicio_usa_el_layout_del_portal(): void
    {
        $respuesta = $this->get('/');

        $respuesta->assertOk();
        $respuesta->assertSee('Reservá tu excursión sin crear una cuenta.');
        $respuesta->assertSee('href="/mi-reserva"', false);
    }

    public function test_el_chip_de_estado_escribe_el_estado(): void
    {
        $html = $this->blade('<x-chip-estado estado="Sin Permiso" />');

        $html->assertSee('Sin Permiso');
        $html->assertSee('text-error');
    }
}
