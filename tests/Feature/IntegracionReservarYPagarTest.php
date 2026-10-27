<?php

namespace Tests\Feature;

use App\Enums\EstadoPaquete;
use App\Enums\EstadoPermiso;
use App\Enums\EstadoReserva;
use App\Enums\EstadoSaldo;
use App\Enums\Rol;
use App\Enums\TipoNotificacion;
use App\Enums\TipoPago;
use App\Jobs\LiberarCupoRetenido;
use App\Models\Excursion;
use App\Models\Guia;
use App\Models\Paquete;
use App\Models\Recorrido;
use App\Models\Reserva;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\MercadoPagoDePrueba;
use Tests\TestCase;

class IntegracionReservarYPagarTest extends TestCase
{
    use RefreshDatabase;

    private Excursion $excursion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-06 10:00:00');
        config([
            'reserva.minutos_retencion' => 5,
            'services.mercadopago.access_token' => 'TOKEN-DE-PRUEBA',
            // Los trabajos se guardan en la misma SQLite en memoria de la prueba.
            'queue.default' => 'database',
            'mail.default' => 'array',
        ]);

        $recorrido = Recorrido::create(['nombre' => 'Camino Inca de 4 días', 'duracion_dias' => 4, 'cantidad_campings' => 3]);
        $paquete = Paquete::create([
            'id_recorrido' => $recorrido->id_recorrido,
            'nombre' => 'Camino Inca Clásico',
            'precio_base' => 750,
            'costo_noche_extra_cusco' => 60,
            'costo_equipo_camping' => 45,
            'cantidad_porteadores' => 6,
            'estado' => EstadoPaquete::Activo,
            'fecha_creacion' => '2026-01-05',
        ]);
        $usuario = Usuario::create(['correo' => 'guia@caminodelinca.test', 'password' => 'guia1234', 'rol' => Rol::Guia]);
        Guia::create(['id_usuario' => $usuario->id_usuario, 'nombre' => 'Rosa', 'apellido' => 'Quispe']);
        $this->excursion = Excursion::create([
            'id_paquete' => $paquete->id_paquete,
            'id_guia' => $usuario->id_usuario,
            'fecha_salida' => '2027-01-18',
            'cupo' => 12,
            'plazas_retenidas' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        MercadoPagoDePrueba::desinstalar();

        parent::tearDown();
    }

    public static function opcionesDePago(): array
    {
        return [
            'INT-01 Seña' => [TipoPago::Sena, 832.5, EstadoSaldo::Adeudado],
            'INT-02 Total' => [TipoPago::Total, 1665.0, EstadoSaldo::Abonado],
        ];
    }

    #[DataProvider('opcionesDePago')]
    public function test_el_hilo_reservar_y_pagar_envia_primera_compra_con_pdf(TipoPago $tipoPago, float $monto, EstadoSaldo $estadoSaldo): void
    {
        Livewire::test('realizar-reserva', ['idPaquete' => $this->excursion->id_paquete, 'fechaSalida' => '2027-01-18'])
            ->set('correoElectronico', 'ana.perez@mail.com')
            ->set('cantidadIntegrantes', 2)
            ->set('nombre', 'Ana')
            ->set('apellido', 'Pérez')
            ->set('documentoPasaporte', 'AAA111')
            ->set('equipoCamping', true)
            ->call('siguienteIntegrante')
            ->set('nombre', 'Bruno')
            ->set('apellido', 'Gómez')
            ->set('documentoPasaporte', 'BBB222')
            ->set('equipoCamping', false)
            ->call('siguienteIntegrante')
            ->assertSet('paso', 2)
            ->call('sumarNoche', 'antes')
            ->call('confirmarReserva')
            ->assertHasNoErrors()
            ->assertRedirect('/reservar/pago');

        $enCurso = session('reserva_en_curso');
        $this->assertSame(2, $this->excursion->fresh()->plazas_retenidas);
        $this->assertSame(10, $this->excursion->fresh()->obtenerCupoDisponible());
        $this->assertSame(now()->addMinutes(5)->toIso8601String(), $enCurso['vence']);
        $this->assertDatabaseCount('reserva', 0);
        $this->assertDatabaseCount('excursionista', 0);
        $this->assertDatabaseCount('pago', 0);
        $this->assertDatabaseCount('comprobante', 0);
        $this->assertDatabaseCount('jobs', 1);
        $retencion = DB::table('jobs')->sole();
        $this->assertSame(LiberarCupoRetenido::class, json_decode($retencion->payload, true)['data']['commandName']);
        $this->assertSame(now()->addMinutes(5)->getTimestamp(), (int) $retencion->available_at);

        $direccionPago = 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=integracion';
        $mercadoPago = MercadoPagoDePrueba::instalar()->responder(['id' => 'integracion', 'init_point' => $direccionPago]);

        Livewire::test('pagar-reserva')
            ->assertSee('USD 1.665,00')
            ->call('realizarPago', $tipoPago->value)
            ->call('confirmarPago')
            ->assertRedirect($direccionPago);

        $pedido = $mercadoPago->datosDelPedido();
        $this->assertEquals($monto, $pedido['items'][0]['unit_price']);
        $this->assertSame('USD', $pedido['items'][0]['currency_id']);
        $this->assertSame($enCurso['id_retencion'].'_'.$tipoPago->name, $pedido['external_reference']);

        $mercadoPago->responder([
            'id' => 183193192230,
            'status' => 'approved',
            'external_reference' => $pedido['external_reference'],
            'payment_type_id' => 'credit_card',
        ]);

        $this->get('/reservar/confirmada?payment_id=183193192230&status=approved')
            ->assertRedirect('/reservar/confirmada');

        $reserva = Reserva::sole();
        $this->assertSame(EstadoReserva::Pendiente, $reserva->estado);
        $this->assertSame($estadoSaldo, $reserva->estado_saldo);
        $this->assertSame('ana.perez@mail.com', $reserva->correo_electronico);
        $this->assertSame('2026-12-18 23:59:59', $reserva->fecha_limite_confirmacion->format('Y-m-d H:i:s'));
        $this->assertSame(1665.0, $reserva->calcularMontoTotal());
        $this->assertSame(1, $reserva->noches_extra_antes);
        $this->assertSame(0, $reserva->noches_extra_despues);
        $this->assertSame(2, $reserva->excursionistas()->count());
        $this->assertSame(2, $reserva->excursionistas()->where('estado_permiso', EstadoPermiso::Pendiente)->count());

        if ($tipoPago === TipoPago::Sena) {
            $this->assertSame('2026-12-18 23:59:59', $reserva->fecha_limite_saldo->format('Y-m-d H:i:s'));
        } else {
            $this->assertNull($reserva->fecha_limite_saldo);
        }

        $this->assertDatabaseCount('pago', 1);
        $this->assertDatabaseCount('comprobante', 1);
        $pago = $reserva->pagos()->sole();
        $this->assertSame($tipoPago, $pago->tipo_pago);
        $this->assertSame($monto, (float) $pago->monto);
        $this->assertSame('Tarjeta de crédito', $pago->medio_pago);
        $this->assertSame(0, $this->excursion->fresh()->plazas_retenidas);
        $this->assertSame(10, $this->excursion->fresh()->obtenerCupoDisponible());
        $this->assertNull(session('reserva_en_curso'));
        $this->get('/reservar/confirmada')->assertOk()->assertSee($reserva->numero_reserva)->assertSee($pago->comprobante->numero_comprobante);

        // El correo está serializado en la cola, junto a la retención demorada todavía no vencida.
        $this->assertDatabaseCount('jobs', 2);
        $payloadCorreo = json_decode(DB::table('jobs')->where('id', '!=', $retencion->id)->sole()->payload, true);
        $this->assertSame(SendQueuedMailable::class, $payloadCorreo['data']['commandName']);
        $correo = unserialize($payloadCorreo['data']['command'])->mailable;
        $this->assertSame(TipoNotificacion::PrimeraCompra, $correo->tipo);
        $this->assertTrue($correo->hasTo('ana.perez@mail.com'));
        $this->assertSame($reserva->numero_reserva, $correo->datos['reserva']['numero_reserva']);
        $this->assertSame($monto, (float) $correo->datos['comprobante']['pago']['monto']);
        $this->assertSame(1665.0, $correo->datos['comprobante']['monto_total']);
        $this->assertSame(round(1665 - $monto, 2), $correo->datos['comprobante']['saldo_despues_pago']);

        // Se procesa el trabajo real, pero el transporte array conserva el correo en memoria: no usa SMTP.
        $this->artisan('queue:work', ['--once' => true])->assertExitCode(0);

        $mensajes = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(1, $mensajes);
        $mensaje = $mensajes->sole()->getOriginalMessage();
        $this->assertSame('ana.perez@mail.com', $mensaje->getTo()[0]->getAddress());
        $this->assertSame('Tu reserva '.$reserva->numero_reserva.' está registrada', $mensaje->getSubject());
        $this->assertStringContainsString($reserva->numero_reserva, $mensaje->getHtmlBody());
        $adjuntos = $mensaje->getAttachments();
        $this->assertCount(1, $adjuntos);
        $this->assertSame('comprobante-'.$pago->comprobante->numero_comprobante.'.pdf', $adjuntos[0]->getFilename());
        $this->assertSame('application', $adjuntos[0]->getMediaType());
        $this->assertSame('pdf', $adjuntos[0]->getMediaSubtype());
        $this->assertStringStartsWith('%PDF', $adjuntos[0]->getBody());
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('jobs', 1);

        $this->travel(6)->minutes();
        $this->artisan('queue:work', ['--once' => true])->assertExitCode(0);

        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame(0, $this->excursion->fresh()->plazas_retenidas);
        $this->assertSame(10, $this->excursion->fresh()->obtenerCupoDisponible());
        $this->assertCount(1, $mensajes);
    }
}
