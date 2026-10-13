<?php

namespace Tests\Feature;

use App\Enums\EstadoReserva;
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

    public function test_la_portada_muestra_los_minutos_de_la_retencion_de_la_configuracion(): void
    {
        config(['reserva.minutos_retencion' => 7]);

        $this->get('/')->assertOk()->assertSee('guardado 7 minutos');
    }

    public function test_con_un_minuto_de_retencion_la_portada_lo_escribe_en_singular(): void
    {
        config(['reserva.minutos_retencion' => 1]);

        $this->get('/')->assertOk()->assertSee('guardado 1 minuto mientras');
    }

    public function test_el_chip_de_estado_escribe_el_estado(): void
    {
        $html = $this->blade('<x-chip-estado :estado="$estado" />', ['estado' => EstadoReserva::SinPermiso]);

        $html->assertSee(EstadoReserva::SinPermiso->value);
        $html->assertSee('text-error');
    }
}
