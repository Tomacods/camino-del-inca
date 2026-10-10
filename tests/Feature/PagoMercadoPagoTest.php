<?php

namespace Tests\Feature;

use App\Enums\TipoPago;
use App\Models\Pago;
use Illuminate\Support\Facades\Log;
use MercadoPago\Exceptions\MPApiException;
use Tests\MercadoPagoDePrueba;
use Tests\TestCase;

// Lo que Pago le pide a Mercado Pago y cómo lee lo que contesta, con el SDK reemplazado (CU-15).
class PagoMercadoPagoTest extends TestCase
{
    private const DIRECCION_MERCADO_PAGO = 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=123-abc';

    private MercadoPagoDePrueba $mercadoPago;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.mercadopago.access_token' => 'TOKEN-DE-PRUEBA']);
        $this->mercadoPago = MercadoPagoDePrueba::instalar();
    }

    protected function tearDown(): void
    {
        MercadoPagoDePrueba::desinstalar();

        parent::tearDown();
    }

    public function test_derivar_pago_pide_el_cobro_en_dolares_y_devuelve_la_direccion_de_mercado_pago(): void
    {
        $this->mercadoPago->responder(['id' => '123-abc', 'init_point' => self::DIRECCION_MERCADO_PAGO]);

        $direccion = Pago::derivarPago([
            'descripcion' => 'Camino Inca Clásico, salida 18-01-2027 (seña)',
            'referencia' => 'retencion-1_Sena',
            'direccion_vuelta' => 'https://ejemplo.test/reservar/confirmada',
            'vence' => '2026-10-06T10:03:00-03:00',
        ], 832.5);

        $this->assertSame(self::DIRECCION_MERCADO_PAGO, $direccion);
        $this->assertCount(1, $this->mercadoPago->pedidos);
        $this->assertSame('POST', $this->mercadoPago->pedidos[0]->getMethod());
        $this->assertSame('/checkout/preferences', $this->mercadoPago->pedidos[0]->getUri());
        // El Access Token sale de config/services.php.
        $this->assertContains('Authorization: Bearer TOKEN-DE-PRUEBA', $this->mercadoPago->pedidos[0]->getHeaders());

        $datos = $this->mercadoPago->datosDelPedido();
        $this->assertSame([[
            'title' => 'Camino Inca Clásico, salida 18-01-2027 (seña)',
            'quantity' => 1,
            'unit_price' => 832.5,
            'currency_id' => 'USD',
        ]], $datos['items']);
        $this->assertSame('retencion-1_Sena', $datos['external_reference']);
        $this->assertSame([
            'success' => 'https://ejemplo.test/reservar/confirmada',
            'failure' => 'https://ejemplo.test/reservar/confirmada',
            'pending' => 'https://ejemplo.test/reservar/confirmada',
        ], $datos['back_urls']);
        $this->assertSame('approved', $datos['auto_return']);
        $this->assertTrue($datos['binary_mode']);
        $this->assertSame([['id' => 'ticket'], ['id' => 'atm']], $datos['payment_methods']['excluded_payment_types']);
        $this->assertTrue($datos['expires']);
        $this->assertSame('2026-10-06T10:03:00.000-03:00', $datos['expiration_date_to']);
    }

    public function test_si_mercado_pago_rechaza_el_pedido_derivar_pago_lo_anota_con_el_motivo_y_lanza_la_excepcion(): void
    {
        Log::spy();
        $this->mercadoPago->responder(['message' => 'expiration_date_to invalid format', 'error' => 'bad_request', 'status' => 400], 400);

        try {
            Pago::derivarPago($this->datosPago(), 832.5);
            $this->fail('derivarPago() tenía que lanzar la excepción.');
        } catch (MPApiException $excepcion) {
            $this->assertSame(400, $excepcion->getStatusCode());
        }

        Log::shouldHaveReceived('error')->once()->withArgs(fn ($mensaje) => str_contains($mensaje, 'HTTP 400')
            && str_contains($mensaje, 'expiration_date_to invalid format'));
    }

    public function test_si_la_respuesta_de_error_no_es_json_igual_lo_anota(): void
    {
        Log::spy();
        $this->mercadoPago->responder(null, 502);

        $this->expectException(MPApiException::class);

        try {
            Pago::derivarPago($this->datosPago(), 832.5);
        } finally {
            Log::shouldHaveReceived('error')->once()->withArgs(fn ($mensaje) => str_contains($mensaje, 'HTTP 502')
                && str_contains($mensaje, 'sin contenido'));
        }
    }

    public function test_si_mercado_pago_no_responde_derivar_pago_lo_anota_y_lanza_la_excepcion(): void
    {
        Log::spy();
        $this->mercadoPago->noResponder();

        $this->expectExceptionMessage('No se pudo conectar con Mercado Pago.');

        try {
            Pago::derivarPago($this->datosPago(), 832.5);
        } finally {
            Log::shouldHaveReceived('error')->once()->withArgs(fn ($mensaje) => str_contains($mensaje, 'No se pudo conectar con Mercado Pago.'));
        }
    }

    public function test_consultar_transaccion_con_el_pago_aprobado_con_tarjeta_de_credito(): void
    {
        $this->mercadoPago->responder($this->pago('approved', 'retencion-1_Total', 'credit_card'));

        $this->assertSame([
            'aprobada' => true,
            'referencia' => 'retencion-1_Total',
            'medio_pago' => 'Tarjeta de crédito',
        ], Pago::consultarTransaccion('183193192230'));

        $this->assertSame('GET', $this->mercadoPago->pedidos[0]->getMethod());
        $this->assertStringEndsWith('/183193192230', $this->mercadoPago->pedidos[0]->getUri());
    }

    public function test_consultar_transaccion_sin_referencia_ni_medio_de_pago_no_da_error(): void
    {
        // Como el pago de prueba del 08/10, que no llevaba referencia. Mercado Pago tampoco informó el medio.
        $this->mercadoPago->responder(['id' => 183193192230, 'status' => 'approved', 'external_reference' => null]);

        $this->assertSame([
            'aprobada' => true,
            'referencia' => null,
            'medio_pago' => 'Mercado Pago',
        ], Pago::consultarTransaccion('183193192230'));
    }

    public function test_consultar_transaccion_con_el_pago_rechazado(): void
    {
        $this->mercadoPago->responder($this->pago('rejected', 'retencion-1_Total', 'credit_card'));

        $this->assertFalse(Pago::consultarTransaccion('183193192230')['aprobada']);
    }

    public function test_consultar_transaccion_traduce_el_medio_de_pago(): void
    {
        $medios = [
            'credit_card' => 'Tarjeta de crédito',
            'debit_card' => 'Tarjeta de débito',
            'account_money' => 'Dinero en cuenta de Mercado Pago',
            'prepaid_card' => 'Tarjeta prepaga',
            'bank_transfer' => 'Mercado Pago',
        ];

        foreach ($medios as $tipoMercadoPago => $medioPago) {
            $this->mercadoPago->responder($this->pago('approved', 'retencion-1_Total', $tipoMercadoPago));

            $this->assertSame($medioPago, Pago::consultarTransaccion('183193192230')['medio_pago'], $tipoMercadoPago);
        }
    }

    public function test_consultar_transaccion_devuelve_null_si_mercado_pago_no_conoce_el_pago(): void
    {
        Log::spy();
        $this->mercadoPago->responder(['message' => 'Payment not found', 'status' => 404], 404);

        $this->assertNull(Pago::consultarTransaccion('999'));

        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($mensaje) => str_contains($mensaje, 'HTTP 404')
            && str_contains($mensaje, 'Payment not found'));
    }

    public function test_consultar_transaccion_devuelve_null_si_mercado_pago_no_responde(): void
    {
        $this->mercadoPago->noResponder();

        $this->assertNull(Pago::consultarTransaccion('183193192230'));
    }

    public function test_la_referencia_va_y_vuelve_con_la_sena(): void
    {
        $referencia = Pago::armarReferencia('9b1d2c3e-0f4a-4b5c-8d6e-7f8091a2b3c4', TipoPago::Sena);

        $this->assertSame('9b1d2c3e-0f4a-4b5c-8d6e-7f8091a2b3c4_Sena', $referencia);
        $this->assertSame(
            ['id_retencion' => '9b1d2c3e-0f4a-4b5c-8d6e-7f8091a2b3c4', 'tipo_pago' => TipoPago::Sena],
            Pago::leerReferencia($referencia),
        );
    }

    public function test_la_referencia_va_y_vuelve_con_el_total(): void
    {
        $referencia = Pago::armarReferencia('9b1d2c3e-0f4a-4b5c-8d6e-7f8091a2b3c4', TipoPago::Total);

        $this->assertSame('9b1d2c3e-0f4a-4b5c-8d6e-7f8091a2b3c4_Total', $referencia);
        $this->assertSame(
            ['id_retencion' => '9b1d2c3e-0f4a-4b5c-8d6e-7f8091a2b3c4', 'tipo_pago' => TipoPago::Total],
            Pago::leerReferencia($referencia),
        );
    }

    public function test_una_referencia_sin_el_formato_no_se_lee(): void
    {
        $this->assertNull(Pago::leerReferencia(null));
        $this->assertNull(Pago::leerReferencia(''));
        $this->assertNull(Pago::leerReferencia('9b1d2c3e-0f4a-4b5c-8d6e-7f8091a2b3c4'));
        $this->assertNull(Pago::leerReferencia('_Total'));
        $this->assertNull(Pago::leerReferencia('9b1d2c3e-0f4a-4b5c-8d6e-7f8091a2b3c4_Saldo'));
        $this->assertNull(Pago::leerReferencia('9b1d2c3e-0f4a-4b5c-8d6e-7f8091a2b3c4_Regalo'));
    }

    private function datosPago(): array
    {
        return [
            'descripcion' => 'Camino Inca Clásico, salida 18-01-2027 (seña)',
            'referencia' => 'retencion-1_Sena',
            'direccion_vuelta' => 'https://ejemplo.test/reservar/confirmada',
            'vence' => '2026-10-06T10:03:00-03:00',
        ];
    }

    // Lo que contesta Mercado Pago al consultar un pago, con lo que lee el sistema.
    private function pago(string $estado, ?string $referencia, string $tipoPago): array
    {
        return [
            'id' => 183193192230,
            'status' => $estado,
            'external_reference' => $referencia,
            'payment_type_id' => $tipoPago,
            'currency_id' => 'ARS',
            'transaction_amount' => 151550,
        ];
    }
}
