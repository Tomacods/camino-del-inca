<?php

namespace Tests\Feature;

use App\Enums\TipoPago;
use App\Models\Pago;
use InvalidArgumentException;
use Tests\TestCase;

class PagoTest extends TestCase
{
    public function test_con_el_total_se_paga_el_monto_completo(): void
    {
        $this->assertSame(2700.0, Pago::calcularMontoAPagar(2700, TipoPago::Total));
    }

    public function test_con_la_sena_se_paga_la_mitad(): void
    {
        $this->assertSame(1350.0, Pago::calcularMontoAPagar(2700, TipoPago::Sena));
    }

    public function test_la_sena_conserva_los_centavos(): void
    {
        $this->assertSame(772.5, Pago::calcularMontoAPagar(1545, TipoPago::Sena));
    }

    public function test_el_porcentaje_de_la_sena_sale_de_la_configuracion(): void
    {
        config(['reserva.porcentaje_sena' => 0.30]);

        $this->assertSame(810.0, Pago::calcularMontoAPagar(2700, TipoPago::Sena));
    }

    public function test_el_saldo_no_se_calcula_aca(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Pago::calcularMontoAPagar(2700, TipoPago::Saldo);
    }
}
