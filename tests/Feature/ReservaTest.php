<?php

namespace Tests\Feature;

use App\Models\Reserva;
use Tests\TestCase;

class ReservaTest extends TestCase
{
    public function test_acepta_ninguna_noche_extra(): void
    {
        $this->assertTrue(Reserva::validarNochesExtra(0, 0));
    }

    public function test_acepta_una_noche_antes_y_una_despues(): void
    {
        $this->assertTrue(Reserva::validarNochesExtra(1, 1));
    }

    public function test_acepta_las_dos_noches_antes_del_recorrido(): void
    {
        $this->assertTrue(Reserva::validarNochesExtra(2, 0));
    }

    public function test_rechaza_mas_de_dos_noches_en_total(): void
    {
        $this->assertFalse(Reserva::validarNochesExtra(2, 1));
    }

    public function test_rechaza_noches_negativas(): void
    {
        $this->assertFalse(Reserva::validarNochesExtra(-1, 0));
        $this->assertFalse(Reserva::validarNochesExtra(0, -1));
        $this->assertFalse(Reserva::validarNochesExtra(-1, 2));
    }

    public function test_el_maximo_de_noches_sale_de_la_configuracion(): void
    {
        config(['reserva.maximo_noches_extra' => 3]);

        $this->assertTrue(Reserva::validarNochesExtra(2, 1));
        $this->assertFalse(Reserva::validarNochesExtra(2, 2));
    }
}
