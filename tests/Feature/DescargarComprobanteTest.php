<?php

namespace Tests\Feature;

use App\Enums\TipoPago;
use App\Models\Pago;
use App\Models\Reserva;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\MercadoPagoDePrueba;
use Tests\TestCase;

class DescargarComprobanteTest extends TestCase
{
    use RefreshDatabase;

    private Reserva $reserva;

    private MercadoPagoDePrueba $mercadoPago;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-20 10:00:00'));
        $this->seed();
        Mail::fake();

        config(['services.mercadopago.access_token' => 'TOKEN-DE-PRUEBA']);

        // Reserva confirmada de los seeders: seña de USD 750 y saldo adeudado de USD 750.
        $this->reserva = Reserva::where('numero_reserva', '000125-9')->sole();
        $this->mercadoPago = MercadoPagoDePrueba::instalar();
    }

    protected function tearDown(): void
    {
        MercadoPagoDePrueba::desinstalar();

        parent::tearDown();
    }

    public function test_cu15_descarga_el_comprobante_mostrado_con_nombre_y_contenido_pdf(): void
    {
        $this->guardarReservaConfirmada();
        $pago = $this->reserva->pagos()->sole();

        $pantalla = Livewire::test('reserva-confirmada')
            ->assertSee('Descargar comprobante')
            ->call('descargarComprobante')
            ->assertFileDownloaded('comprobante-'.$pago->comprobante->numero_comprobante.'.pdf', contentType: 'application/pdf');

        $this->assertStringStartsWith('%PDF', base64_decode($pantalla->effects['download']['content']));
        $this->assertSame([], $this->mercadoPago->pedidos);
    }

    public function test_cu15_sin_reserva_confirmada_en_la_sesion_no_descarga(): void
    {
        Livewire::test('reserva-confirmada')
            ->assertDontSee('Descargar comprobante')
            ->call('descargarComprobante')
            ->assertNoFileDownloaded();
    }

    public function test_cu15_si_la_sesion_pierde_la_reserva_no_descarga(): void
    {
        $this->guardarReservaConfirmada();
        $pantalla = Livewire::test('reserva-confirmada')->assertSee('Descargar comprobante');

        session()->forget('reserva_confirmada');

        $pantalla->call('descargarComprobante')->assertNoFileDownloaded();
    }

    public function test_cu16_descarga_el_comprobante_del_saldo_y_no_el_de_la_sena(): void
    {
        $this->darAcceso();
        $pagoSaldo = $this->pagarSaldo();
        $pagoSena = $this->reserva->pagos()->where('tipo_pago', TipoPago::Sena)->sole();

        $this->assertNotSame($pagoSena->comprobante->numero_comprobante, $pagoSaldo->comprobante->numero_comprobante);

        $pantalla = $this->abrirSaldoPagado()
            ->assertSee('Descargar comprobante')
            ->assertSee($pagoSaldo->comprobante->numero_comprobante)
            ->assertDontSee($pagoSena->comprobante->numero_comprobante)
            ->call('descargarComprobante')
            ->assertFileDownloaded('comprobante-'.$pagoSaldo->comprobante->numero_comprobante.'.pdf', contentType: 'application/pdf');

        $this->assertStringStartsWith('%PDF', base64_decode($pantalla->effects['download']['content']));
        $this->assertCount(1, $this->mercadoPago->pedidos);
    }

    public function test_cu16_sin_acceso_en_la_sesion_no_descarga(): void
    {
        $this->pagarSaldo();

        $this->abrirSaldoPagado()
            ->assertSee('Registramos tu pago')
            ->assertDontSee('Descargar comprobante')
            ->call('descargarComprobante')
            ->assertNoFileDownloaded();
    }

    public function test_cu16_con_el_acceso_de_otra_reserva_no_descarga(): void
    {
        $this->pagarSaldo();
        session(['acceso_reserva' => [
            'correo' => 'valentina.diaz@mail.com',
            'numeroReserva' => '000122-3',
        ]]);

        $this->abrirSaldoPagado()
            ->assertDontSee('Descargar comprobante')
            ->call('descargarComprobante')
            ->assertNoFileDownloaded();
    }

    public function test_cu16_con_el_mismo_correo_pero_el_numero_de_otra_reserva_no_descarga(): void
    {
        $this->pagarSaldo();
        session(['acceso_reserva' => [
            'correo' => $this->reserva->correo_electronico,
            'numeroReserva' => '000122-3',
        ]]);

        $this->abrirSaldoPagado()
            ->assertDontSee('Descargar comprobante')
            ->call('descargarComprobante')
            ->assertNoFileDownloaded();
    }

    public function test_cu16_si_la_sesion_pierde_el_acceso_no_descarga(): void
    {
        $this->darAcceso();
        $this->pagarSaldo();
        $pantalla = $this->abrirSaldoPagado()->assertSee('Descargar comprobante');

        session()->forget('acceso_reserva');

        $pantalla->call('descargarComprobante')->assertNoFileDownloaded();
    }

    private function guardarReservaConfirmada(): void
    {
        session(['reserva_confirmada' => [
            'id_retencion' => 'retencion-de-prueba',
            'numero_reserva' => $this->reserva->numero_reserva,
            'correo_electronico' => $this->reserva->correo_electronico,
        ]]);
    }

    private function darAcceso(): void
    {
        session(['acceso_reserva' => [
            'correo' => $this->reserva->correo_electronico,
            'numeroReserva' => $this->reserva->numero_reserva,
        ]]);
    }

    private function pagarSaldo(): Pago
    {
        $this->mercadoPago->responder([
            'id' => 183193192230,
            'status' => 'approved',
            'external_reference' => $this->reserva->numero_reserva.'_Saldo',
            'payment_type_id' => 'credit_card',
            'currency_id' => 'ARS',
            'transaction_amount' => 1095000,
        ]);

        $this->get('/mi-reserva/'.$this->reserva->numero_reserva.'/saldo-pagado?payment_id=183193192230')
            ->assertRedirect('/mi-reserva/'.$this->reserva->numero_reserva.'/saldo-pagado');

        return $this->reserva->pagos()->where('tipo_pago', TipoPago::Saldo)->sole();
    }

    private function abrirSaldoPagado()
    {
        return Livewire::test('saldo-pagado', ['numeroReserva' => $this->reserva->numero_reserva]);
    }
}
