<?php

namespace Tests\Feature;

use App\Models\Paquete;
use Tests\TestCase;

class PaqueteTest extends TestCase
{
    private Paquete $paquete;

    protected function setUp(): void
    {
        parent::setUp();

        // El paquete Clásico de las otras pruebas. No hace falta guardarlo: la cuenta usa sólo sus precios.
        $this->paquete = new Paquete([
            'precio_base' => 750,
            'costo_noche_extra_cusco' => 60,
            'costo_equipo_camping' => 45,
        ]);
    }

    public function test_un_integrante_sin_extras_paga_el_precio_base(): void
    {
        $this->assertSame(750.0, $this->paquete->calcularMonto(1, 0, 0));
    }

    public function test_la_reserva_del_prototipo(): void
    {
        // 3 × 750 + 2 noches × 3 × 60 + 2 equipos × 45.
        $this->assertSame(2700.0, $this->paquete->calcularMonto(3, 2, 2));
    }

    public function test_las_noches_extra_se_cobran_por_integrante(): void
    {
        // 2 × 750 + 1 noche × 2 × 60.
        $this->assertSame(1620.0, $this->paquete->calcularMonto(2, 1, 0));
    }

    public function test_el_equipo_de_camping_se_cobra_por_equipo(): void
    {
        // 2 × 750 + 1 equipo × 45.
        $this->assertSame(1545.0, $this->paquete->calcularMonto(2, 0, 1));
    }
}
