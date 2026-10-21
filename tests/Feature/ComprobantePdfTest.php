<?php

namespace Tests\Feature;

use App\Enums\TipoPago;
use App\Models\Pago;
use App\Models\Reserva;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class ComprobantePdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->travelTo('2026-10-14 12:00:00');
    }

    public function test_reune_los_datos_del_comprobante_del_viaje_y_del_pago(): void
    {
        $reserva = Reserva::buscarReserva('000124-7');
        $pago = $reserva->pagos()->sole();

        $datos = $reserva->getDatosComprobante($pago);

        $this->assertSame('0001-00000007', $datos['numero_comprobante']);
        $this->assertSame('30/09/2026 10:30', $datos['fecha_emision']->format('d/m/Y H:i'));
        $this->assertSame('000124-7', $datos['reserva']['numero_reserva']);
        $this->assertSame('lucia.fernandez@mail.com', $datos['reserva']['correo_electronico']);
        $this->assertSame('Camino Inca Clásico', $datos['excursion']['paquete']);
        $this->assertSame('18/01/2027', $datos['excursion']['fecha_salida']->format('d/m/Y'));
        $this->assertSame('Camino Inca de 4 días', $datos['paquete']['recorrido']['nombre_recorrido']);
        $this->assertEquals(4, $datos['paquete']['recorrido']['duracion_dias']);
        $this->assertCount(8, $datos['paquete']['servicios']);
        $this->assertContains('Camping Wiñay Wayna', array_column($datos['paquete']['servicios'], 'nombre'));
        $this->assertSame(1, $datos['reserva']['noches_extra_antes']);
        $this->assertSame(1, $datos['reserva']['noches_extra_despues']);
        $this->assertSame(2, $datos['noches_extra']);
        $this->assertSame(2700.0, $datos['monto_total']);
        $this->assertSame(1350.0, $datos['saldo_despues_pago']);
        $this->assertSame(TipoPago::Sena, $datos['pago']['tipo_pago']);
        $this->assertSame('Tarjeta de crédito', $datos['pago']['medio_pago']);
        $this->assertSame(1350.0, $datos['pago']['monto']);
        $this->assertSame([
            ['nombre' => 'Lucía', 'apellido' => 'Fernández'],
            ['nombre' => 'Martín', 'apellido' => 'Fernández'],
            ['nombre' => 'Sofía', 'apellido' => 'Ruiz'],
        ], $datos['excursionistas']);
    }

    public function test_el_saldo_del_comprobante_de_sena_no_cambia_al_pagar_el_saldo_el_mismo_dia(): void
    {
        $this->travelTo('2026-10-01 18:20:00');
        $reserva = Reserva::buscarReserva('000125-9');
        $sena = $reserva->pagos()->sole();
        $datosSena = $reserva->getDatosComprobante($sena);

        $saldo = Pago::registrarPagoSaldo($reserva, 'Tarjeta de débito');

        $this->assertNotNull($saldo);
        $this->assertTrue($sena->fecha->isSameDay($saldo->fecha));
        $this->assertGreaterThan($sena->id_pago, $saldo->id_pago);

        $reserva->refresh();

        $this->assertSame(750.0, $datosSena['saldo_despues_pago']);
        $this->assertSame(750.0, $reserva->getDatosComprobante($sena)['saldo_despues_pago']);
        $this->assertSame(0.0, $reserva->getDatosComprobante($saldo)['saldo_despues_pago']);
        $this->assertSame(0.0, $reserva->calcularSaldoPendiente());
    }

    public function test_el_pago_total_tiene_saldo_cero_en_el_comprobante(): void
    {
        $reserva = Reserva::buscarReserva('000122-3');

        $datos = $reserva->getDatosComprobante($reserva->pagos()->sole());

        $this->assertSame(TipoPago::Total, $datos['pago']['tipo_pago']);
        $this->assertSame(1710.0, $datos['monto_total']);
        $this->assertSame(0.0, $datos['saldo_despues_pago']);
    }

    public function test_la_vista_muestra_los_datos_del_comprobante_sin_consultar_la_base(): void
    {
        $reserva = Reserva::buscarReserva('000124-7');
        $datos = $reserva->getDatosComprobante($reserva->pagos()->sole());
        DB::enableQueryLog();
        DB::flushQueryLog();

        $html = view('comprobantes.pago', ['datos' => $datos])->render();

        $consultas = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(0, $consultas);
        $textoRenderizado = html_entity_decode(preg_replace('/\s+/u', ' ', strip_tags($html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        foreach ([
            '0001-00000007',
            '30/09/2026 10:30',
            '000124-7',
            'lucia.fernandez@mail.com',
            'Camino Inca Clásico',
            'Camino Inca de 4 días',
            '4 días',
            '18/01/2027',
            'Lucía Fernández',
            'Martín Fernández',
            'Sofía Ruiz',
            'Seña',
            'Tarjeta de crédito',
            'Noches extra en Cusco',
            '2 (antes: 1; después: 1)',
            'Precio final de la reserva',
            'USD 2.700,00',
            'Saldo después de este pago',
            'USD 1.350,00',
            'Los montos están en dólares estadounidenses. Mercado Pago cobra el equivalente en pesos argentinos, con su cotización del momento del pago.',
        ] as $texto) {
            $this->assertStringContainsString($texto, $textoRenderizado);
        }

        foreach ($datos['paquete']['servicios'] as $servicio) {
            $this->assertStringContainsString($servicio['tipo']->value, $html);
            $this->assertStringContainsString($servicio['nombre'], $html);
        }

        $this->assertStringContainsString('DejaVu Sans', $html);
    }

    public function test_rechaza_un_pago_que_pertenece_a_otra_reserva(): void
    {
        $reserva = Reserva::buscarReserva('000124-7');
        $pagoAjeno = Reserva::buscarReserva('000125-9')->pagos()->sole();

        $this->expectException(InvalidArgumentException::class);

        $reserva->getDatosComprobante($pagoAjeno);
    }
}
