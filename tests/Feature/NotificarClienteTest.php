<?php

namespace Tests\Feature;

use App\Enums\EstadoPermiso;
use App\Enums\EstadoReserva;
use App\Enums\TipoNotificacion;
use App\Mail\NotificacionCliente;
use App\Models\Pago;
use App\Models\Reserva;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ServicioCorreoQueFalla;
use Tests\TestCase;

// Notificar Cliente (CU-13), con las reservas de los seeders. Las invocaciones desde cada pantalla se prueban en
// ReservaConfirmadaTest (CU-15), SaldoPagadoTest (CU-16) y ValidarPermisosTest (CU-04).
class NotificarClienteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Dentro de la transacción de cada prueba, que al terminar se deshace. Con $seed = true sólo se sembraría si
        // esta fuera la primera clase en migrar la base en memoria, que las pruebas comparten.
        $this->seed();

        // Antes de todas las fechas límite de los seeders que se usan acá.
        $this->travelTo('2026-10-14 12:00:00');
    }

    /* --------------------------- Los ocho tipos ------------------------------- */

    public function test_primera_compra_lleva_el_numero_de_reserva_y_el_comprobante(): void
    {
        Mail::fake();
        $reserva = Reserva::buscarReserva('000124-7');

        $this->assertTrue(Reserva::notificarCliente(TipoNotificacion::PrimeraCompra, '000124-7'));

        $correo = $this->correoEnviado(TipoNotificacion::PrimeraCompra, 'lucia.fernandez@mail.com');
        $correo->assertHasSubject('Tu reserva 000124-7 está registrada');

        $paquete = $reserva->excursion->paquete;
        $correo->assertSeeInOrderInHtml([
            '000124-7',
            $reserva->pagos()->sole()->comprobante->numero_comprobante,
            'Camino Inca Clásico, salida el 18/01/2027',
            $paquete->recorrido->nombre.' ('.$paquete->recorrido->duracion_dias.' días)',
        ]);
        foreach ($paquete->servicios as $servicio) {
            $correo->assertSeeInHtml($servicio->tipo->value.': '.$servicio->nombre);
        }
        $correo->assertSeeInHtml('1 antes y 1 después del recorrido');
        $correo->assertSeeInOrderInHtml(['Precio final', 'USD 2.700,00', 'Abonado (Seña)', 'USD 1.350,00']);

        // La tabla común: los pagos, el saldo que falta con su fecha límite y el plazo de los permisos.
        $correo->assertSeeInOrderInHtml([
            'Pagos', 'Seña · 30/09/2026 · USD 1.350,00',
            'Saldo pendiente', 'USD 1.350,00, hasta el 18/12/2026',
            'Confirmación de permisos', 'hasta el 18/12/2026',
        ]);
    }

    public function test_pago_de_saldo_confirma_el_pago_total_con_su_comprobante(): void
    {
        Mail::fake();
        $pagoSaldo = Pago::registrarPagoSaldo(Reserva::buscarReserva('000125-9'), 'Tarjeta de débito');

        Reserva::notificarCliente(TipoNotificacion::PagoSaldo, '000125-9');

        $correo = $this->correoEnviado(TipoNotificacion::PagoSaldo, 'federico.rios@mail.com');
        $correo->assertHasSubject('Recibimos el pago del saldo de tu reserva 000125-9');
        $correo->assertSeeInHtml('está pagada por completo');
        $correo->assertSeeInOrderInHtml([
            $pagoSaldo->comprobante->numero_comprobante,
            'Monto abonado', $this->dolares($pagoSaldo->monto).', con Tarjeta de débito',
            'Saldo', 'Abonado',
        ]);
    }

    #[DataProvider('tiposConComprobante')]
    public function test_el_comprobante_es_el_del_ultimo_pago(TipoNotificacion $tipoNotificacion): void
    {
        Mail::fake();
        $reserva = Reserva::buscarReserva('000125-9');
        $comprobanteSena = $reserva->pagos()->sole()->comprobante->numero_comprobante;
        $comprobanteSaldo = Pago::registrarPagoSaldo($reserva, 'Tarjeta de débito')->comprobante->numero_comprobante;

        Reserva::notificarCliente($tipoNotificacion, '000125-9');

        $correo = $this->correoEnviado($tipoNotificacion, 'federico.rios@mail.com');
        $correo->assertSeeInHtml($comprobanteSaldo);
        $correo->assertDontSeeInHtml($comprobanteSena);
    }

    public static function tiposConComprobante(): array
    {
        return [
            'Primera Compra' => [TipoNotificacion::PrimeraCompra],
            'Pago de Saldo' => [TipoNotificacion::PagoSaldo],
        ];
    }

    #[DataProvider('tiposConComprobante')]
    public function test_primera_compra_y_pago_de_saldo_adjuntan_el_pdf_del_ultimo_pago(TipoNotificacion $tipoNotificacion): void
    {
        Mail::fake();
        $reserva = Reserva::buscarReserva('000125-9');
        $pagoSaldo = Pago::registrarPagoSaldo($reserva, 'Tarjeta de débito');

        Reserva::notificarCliente($tipoNotificacion, '000125-9');

        $correo = $this->correoEnviado($tipoNotificacion, 'federico.rios@mail.com');
        $adjuntos = $correo->attachments();
        $this->assertCount(1, $adjuntos);
        $this->assertSame('comprobante-'.$pagoSaldo->comprobante->numero_comprobante.'.pdf', $adjuntos[0]->as);
        $this->assertSame('application/pdf', $adjuntos[0]->mime);
        $contenido = $adjuntos[0]->attachWith(
            fn () => $this->fail('El comprobante debe generarse desde los datos del correo.'),
            fn ($datos) => $datos(),
        );
        $this->assertStringStartsWith('%PDF', $contenido);
        $correo->assertSeeInHtml('Te adjuntamos el comprobante en PDF.');
    }

    #[DataProvider('tiposSinComprobante')]
    public function test_los_otros_seis_tipos_no_adjuntan_un_pdf(TipoNotificacion $tipoNotificacion): void
    {
        Mail::fake();

        // Esta reserva admite también Permisos Rechazados, que requiere al menos un permiso no obtenido.
        Reserva::notificarCliente($tipoNotificacion, '000123-5');

        $correo = $this->correoEnviado($tipoNotificacion, 'diego.morales@mail.com');
        $this->assertSame([], $correo->attachments());
        $correo->assertDontSeeInHtml('Te adjuntamos el comprobante en PDF.');
    }

    public static function tiposSinComprobante(): array
    {
        return [
            'Cancelación de Reserva' => [TipoNotificacion::CancelacionReserva],
            'Permisos Obtenidos' => [TipoNotificacion::PermisosObtenidos],
            'Permisos Rechazados' => [TipoNotificacion::PermisosRechazados],
            'Cancelación por Falta de Pago' => [TipoNotificacion::CancelacionFaltaPago],
            'Etapa Final' => [TipoNotificacion::EtapaFinal],
            'Cancelación por Falta de Permisos' => [TipoNotificacion::CancelacionFaltaPermisos],
        ];
    }

    public function test_el_adjunto_se_genera_al_enviar_con_los_datos_guardados_al_encolar(): void
    {
        Mail::fake();
        $reserva = Reserva::buscarReserva('000125-9');
        $pago = $reserva->pagos()->sole();
        $numeroComprobante = $pago->comprobante->numero_comprobante;

        Reserva::notificarCliente(TipoNotificacion::PrimeraCompra, '000125-9');

        // La cola serializa el mensaje, y el PDF todavía no está generado ni guardado como texto binario.
        $correo = unserialize(serialize($this->correoEnviado(TipoNotificacion::PrimeraCompra, 'federico.rios@mail.com')));
        $datosComprobante = $correo->datos['comprobante'];
        $this->assertStringNotContainsString('%PDF', serialize($datosComprobante));

        $reserva->update(['noches_extra_antes' => 1, 'correo_electronico' => 'nuevo@mail.com']);
        $reserva->excursion->paquete->update(['nombre' => 'Paquete modificado', 'precio_base' => 9999]);
        $reserva->excursionistas()->update(['nombre' => 'Nombre modificado']);
        $pago->update(['medio_pago' => 'Medio modificado']);
        $pago->comprobante->update(['numero_comprobante' => '0001-99999999']);

        // Al salir de la cola, el adjunto no vuelve a consultar modelos que ahora contienen otros datos.
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $adjunto = $correo->attachments()[0];
            $contenido = $adjunto->attachWith(
                fn () => $this->fail('El comprobante debe generarse desde los datos del correo.'),
                fn ($datos) => $datos(),
            );
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }

        $this->assertSame($datosComprobante, $correo->datos['comprobante']);
        $this->assertSame('comprobante-'.$numeroComprobante.'.pdf', $adjunto->as);
        $this->assertStringStartsWith('%PDF', $contenido);
        $vistaComprobante = view('comprobantes.pago', ['datos' => $correo->datos['comprobante']])->render();
        $this->assertStringContainsString('federico.rios@mail.com', $vistaComprobante);
        $this->assertStringContainsString('Camino Inca Clásico', $vistaComprobante);
        $this->assertStringContainsString('Federico Ríos', $vistaComprobante);
        $this->assertStringContainsString($numeroComprobante, $vistaComprobante);
        $this->assertStringNotContainsString('Paquete modificado', $vistaComprobante);
        $this->assertStringNotContainsString('Nombre modificado', $vistaComprobante);
        $this->assertStringNotContainsString('Medio modificado', $vistaComprobante);
        $this->assertStringNotContainsString('nuevo@mail.com', $vistaComprobante);
        $correo->assertSeeInHtml('Camino Inca Clásico');
        $correo->assertDontSeeInHtml('Paquete modificado');
        $correo->assertDontSeeInHtml('Nombre modificado');
        $correo->assertDontSeeInHtml('Medio modificado');
        $correo->assertDontSeeInHtml('nuevo@mail.com');
    }

    // CU-21 con más de 30 días de anticipación: el 50 % de lo abonado. La fecha de cancelación es la del envío.
    public function test_cancelacion_de_reserva_con_reembolso(): void
    {
        Mail::fake();
        $reserva = Reserva::buscarReserva('000124-7');
        $reserva->confirmarCancelacion($reserva->calcularReembolso(now()), now());

        Reserva::notificarCliente(TipoNotificacion::CancelacionReserva, '000124-7');

        $correo = $this->correoEnviado(TipoNotificacion::CancelacionReserva, 'lucia.fernandez@mail.com');
        $correo->assertHasSubject('Tu reserva 000124-7 fue cancelada');
        $correo->assertSeeInHtml('fue cancelada el 14/10/2026');
        $correo->assertSeeInHtml('Camino Inca Clásico');
        $correo->assertSeeInOrderInHtml(['más de 30 días', 'el 50 % de lo abonado', 'un reembolso de', 'USD 675,00', 'registrado el 14/10/2026']);
        $correo->assertDontSeeInHtml('no tiene devolución');
    }

    // CU-21 con 30 días o menos de anticipación: no se registra devolución.
    public function test_cancelacion_de_reserva_sin_devolucion(): void
    {
        Mail::fake();
        $this->travelTo('2026-12-25 10:00:00');
        $reserva = Reserva::buscarReserva('000124-7');
        $reserva->confirmarCancelacion($reserva->calcularReembolso(now()), now());

        Reserva::notificarCliente(TipoNotificacion::CancelacionReserva, '000124-7');

        $correo = $this->correoEnviado(TipoNotificacion::CancelacionReserva, 'lucia.fernandez@mail.com');
        $correo->assertSeeInHtml('fue cancelada el 25/12/2026');
        $correo->assertSeeInHtml('la cancelación no tiene devolución');
        $correo->assertDontSeeInHtml('reembolso de');
    }

    public function test_permisos_obtenidos_con_saldo_adeudado_informa_el_saldo_y_su_fecha_limite(): void
    {
        Mail::fake();
        $saldoPendiente = Reserva::buscarReserva('000125-9')->calcularSaldoPendiente();

        Reserva::notificarCliente(TipoNotificacion::PermisosObtenidos, '000125-9');

        $correo = $this->correoEnviado(TipoNotificacion::PermisosObtenidos, 'federico.rios@mail.com');
        $correo->assertHasSubject('Tu reserva 000125-9 está confirmada');
        $correo->assertSeeInHtml('Todos los integrantes obtuvieron el permiso');
        $correo->assertSeeInHtml('«Confirmada»');
        $correo->assertSeeInOrderInHtml(['Te queda un saldo de', $this->dolares($saldoPendiente), 'hasta el 01/01/2027']);
    }

    // Si los permisos se validaron el último día, el saldo vence a las 72 horas, a cualquier hora (CU-16): el correo da
    // la fecha y la hora, en el texto y en la tabla.
    public function test_permisos_obtenidos_muestra_la_fecha_y_la_hora_del_plazo_del_saldo(): void
    {
        Mail::fake();
        Reserva::buscarReserva('000125-9')->update(['fecha_limite_saldo' => '2027-01-04 22:00:00']);

        Reserva::notificarCliente(TipoNotificacion::PermisosObtenidos, '000125-9');

        $correo = $this->correoEnviado(TipoNotificacion::PermisosObtenidos, 'federico.rios@mail.com');
        $correo->assertSeeInOrderInHtml([
            'que podés pagar desde Mi reserva hasta el 04/01/2027 a las 22:00.',
            'Saldo pendiente', 'hasta el 04/01/2027 a las 22:00',
        ]);
    }

    public function test_permisos_obtenidos_con_saldo_abonado_no_pide_saldo(): void
    {
        Mail::fake();

        Reserva::notificarCliente(TipoNotificacion::PermisosObtenidos, '000122-3');

        $correo = $this->correoEnviado(TipoNotificacion::PermisosObtenidos, 'valentina.diaz@mail.com');
        $correo->assertSeeInHtml('Tu reserva ya está pagada por completo.');
        $correo->assertDontSeeInHtml('Te queda un saldo');
    }

    public function test_permisos_rechazados_nombra_a_quienes_no_obtuvieron_el_permiso_y_las_opciones(): void
    {
        Mail::fake();

        Reserva::notificarCliente(TipoNotificacion::PermisosRechazados, '000123-5');

        $correo = $this->correoEnviado(TipoNotificacion::PermisosRechazados, 'diego.morales@mail.com');
        $correo->assertHasSubject('Tu reserva 000123-5 quedó sin permiso');

        // En la lista de los que no obtuvieron el permiso está Camila y no Diego, que sí lo obtuvo.
        $sinPermiso = Str::between($correo->render(), 'no obtuvieron el permiso', 'Por eso');
        $this->assertStringContainsString('Camila Morales', $sinPermiso);
        $this->assertStringNotContainsString('Diego Morales', $sinPermiso);

        $correo->assertSeeInOrderInHtml([
            '«Sin Permiso»', 'Hasta el', '04/12/2026',
            'reintegro de todo lo abonado: USD 870,00',
            'Reprogramar la reserva',
            'la reserva se cancela y te reintegramos todo lo abonado',
        ]);

        // En «Sin Permiso» el saldo ya no se paga: el correo no lo pide.
        $correo->assertDontSeeInHtml('Saldo pendiente');
    }

    // CU-04 valida hasta las 23:59:59 del día límite y el correo se pide después: si ya pasó la medianoche, igual sale con
    // el monto del reintegro, aunque CU-17 ya no lo admita.
    public function test_permisos_rechazados_sale_aunque_el_plazo_haya_vencido_al_enviarlo(): void
    {
        Mail::fake();
        $this->travelTo('2026-12-05 00:00:00');

        $this->assertTrue(Reserva::notificarCliente(TipoNotificacion::PermisosRechazados, '000123-5'));

        $correo = $this->correoEnviado(TipoNotificacion::PermisosRechazados, 'diego.morales@mail.com');
        $correo->assertSeeInHtml('reintegro de todo lo abonado: USD 870,00');
    }

    // CU-22 todavía no existe: se simula su cambio de estado.
    public function test_cancelacion_por_falta_de_pago_avisa_que_la_sena_no_se_devuelve(): void
    {
        Mail::fake();
        $reserva = Reserva::buscarReserva('000125-9');
        $reserva->estado = EstadoReserva::Cancelada;
        $reserva->save();

        Reserva::notificarCliente(TipoNotificacion::CancelacionFaltaPago, '000125-9');

        $correo = $this->correoEnviado(TipoNotificacion::CancelacionFaltaPago, 'federico.rios@mail.com');
        $correo->assertHasSubject('Tu reserva 000125-9 fue cancelada por falta de pago');
        $correo->assertSeeInHtml('el saldo no se pagó antes de la fecha límite, el 01/01/2027 a las 23:59.');
        $correo->assertSeeInHtml('no es reembolsable');
    }

    // CU-12 todavía no existe: se usa la reserva «Finalizada» de los seeders.
    public function test_etapa_final_invita_a_valorar_los_servicios(): void
    {
        Mail::fake();

        Reserva::notificarCliente(TipoNotificacion::EtapaFinal, '000118-1');

        $correo = $this->correoEnviado(TipoNotificacion::EtapaFinal, 'paula.benitez@mail.com');
        $correo->assertHasSubject('Tu excursión terminó: contanos cómo te fue');
        $correo->assertSeeInHtml('«Finalizada»');
        $correo->assertSeeInHtml('Ya podés valorar los servicios');
    }

    // La causa la dice quien cancela: CU-17 (el cliente pidió el reintegro) o CU-26 (venció la fecha límite de
    // confirmación). No se deduce de la fecha: aunque el cliente lo pida en el último segundo del plazo, el correo dice
    // que lo pidió él. Pasadas las 23:59:59 del 04/12/2026, CU-17 ya no admite el reintegro (SolicitarReintegroTest) y
    // la cancela CU-26.
    #[DataProvider('causasFaltaDePermisos')]
    public function test_cancelacion_por_falta_de_permisos_reintegra_todo_e_informa_la_causa(string $fecha, bool $canceladaPorVencimiento, string $causa, string $otraCausa): void
    {
        Mail::fake();
        $this->travelTo($fecha);
        $reserva = Reserva::buscarReserva('000123-5');

        if ($canceladaPorVencimiento) {
            $this->assertTrue($reserva->ejecutarCancelacionPorVencimiento(now()));
        } else {
            $reserva->confirmarReintegro(now());
        }

        Reserva::notificarCliente(TipoNotificacion::CancelacionFaltaPermisos, '000123-5', canceladaPorVencimiento: $canceladaPorVencimiento);

        $correo = $this->correoEnviado(TipoNotificacion::CancelacionFaltaPermisos, 'diego.morales@mail.com');
        $correo->assertHasSubject('Tu reserva 000123-5 fue cancelada por falta de permisos');
        $correo->assertSeeInHtml($causa);
        $correo->assertDontSeeInHtml($otraCausa);
        $correo->assertSeeInHtml('Te reintegramos todo lo abonado: un reintegro de <strong>USD 870,00</strong>', false);
    }

    public static function causasFaltaDePermisos(): array
    {
        $pedido = 'porque pediste el reintegro';
        $vencimiento = 'porque el 04/12/2026 venció el plazo';

        return [
            'pedido del cliente (CU-17)' => ['2026-10-14 12:00:00', false, $pedido, $vencimiento],
            'pedido del cliente en el último segundo del plazo' => ['2026-12-04 23:59:59', false, $pedido, $vencimiento],
            'vencimiento de la fecha límite (CU-26)' => ['2026-12-05 03:00:00', true, $vencimiento, $pedido],
        ];
    }

    // CU-26 también cancela reservas «Pendiente». El correo da la misma causa que a una «Sin Permiso», como dice el A7:
    // venció el plazo sin que la reserva quedara confirmada. 000124-7 tiene los tres permisos sin registrar.
    public function test_cancelacion_por_vencimiento_de_una_reserva_pendiente_da_la_misma_causa(): void
    {
        Mail::fake();
        $this->travelTo('2026-12-19 00:05:00');
        $this->assertTrue(Reserva::buscarReserva('000124-7')->ejecutarCancelacionPorVencimiento(now()));

        Reserva::notificarCliente(TipoNotificacion::CancelacionFaltaPermisos, '000124-7', canceladaPorVencimiento: true);

        $correo = $this->correoEnviado(TipoNotificacion::CancelacionFaltaPermisos, 'lucia.fernandez@mail.com');
        $correo->assertSeeInHtml('porque el 18/12/2026 venció el plazo de confirmación sin que la reserva quedara confirmada.');
        $correo->assertDontSeeInHtml('porque pediste el reintegro');
        $correo->assertSeeInHtml('Te reintegramos todo lo abonado: un reintegro de <strong>USD 1.350,00</strong>', false);
    }

    /* ----------------------------- Destinatario ------------------------------- */

    // El correo va sólo al titular. Los excursionistas no tienen correo en el esquema, y el de la reserva es el único.
    public function test_va_solo_al_correo_de_la_reserva(): void
    {
        Mail::fake();

        // 000123-5 está en «Sin Permiso», el único estado en que vale «Permisos Rechazados»: así salen los ocho tipos.
        foreach (TipoNotificacion::cases() as $tipoNotificacion) {
            $this->assertTrue(Reserva::notificarCliente($tipoNotificacion, '000123-5'));
        }

        Mail::assertQueuedCount(count(TipoNotificacion::cases()));
        Mail::assertQueued(NotificacionCliente::class, fn (NotificacionCliente $correo) => array_column($correo->to, 'address') === ['diego.morales@mail.com']
            && $correo->cc === [] && $correo->bcc === []);
    }

    // Excursionista::getDatos() también lo usa CU-10 con el documento: el correo toma sólo lo que dice el DS-13.
    public function test_el_correo_no_lleva_el_documento_de_los_integrantes(): void
    {
        Mail::fake();
        $reserva = Reserva::buscarReserva('000125-9');

        Reserva::notificarCliente(TipoNotificacion::PermisosObtenidos, '000125-9');

        $correo = $this->correoEnviado(TipoNotificacion::PermisosObtenidos, 'federico.rios@mail.com');
        foreach ($correo->datos['excursionistas'] as $integrante) {
            $this->assertSame(['nombre', 'apellido', 'estado_permiso'], array_keys($integrante));
        }
        foreach ($reserva->excursionistas as $excursionista) {
            $correo->assertSeeInHtml($excursionista->nombre.' '.$excursionista->apellido);
            $correo->assertDontSeeInHtml($excursionista->documento_pasaporte);
        }
    }

    public function test_una_reserva_que_no_existe_no_envia_nada(): void
    {
        Mail::fake();
        Log::spy();

        $this->assertFalse(Reserva::notificarCliente(TipoNotificacion::PrimeraCompra, '999999-9'));

        Mail::assertNothingQueued();
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($mensaje) => str_contains($mensaje, '999999-9'));
    }

    /* ---------------------------------- A8 ------------------------------------ */

    public function test_el_correo_va_en_la_cola_con_reintentos(): void
    {
        Queue::fake();

        Reserva::notificarCliente(TipoNotificacion::PrimeraCompra, '000124-7');

        Queue::assertPushed(SendQueuedMailable::class, fn (SendQueuedMailable $tarea) => $tarea->tries === NotificacionCliente::INTENTOS
            && $tarea->backoff() === NotificacionCliente::SEGUNDOS_ENTRE_INTENTOS);
    }

    // Con la cola de la base, como en .env.example: el servicio de correo falla, la reserva conserva su nuevo estado y
    // el correo vuelve a la cola para otro intento, cada vez con más espera.
    public function test_si_el_envio_falla_la_reserva_conserva_su_estado_y_el_correo_se_reintenta(): void
    {
        config(['queue.default' => 'database']);
        ServicioCorreoQueFalla::instalar();

        $reserva = $this->validarTodosLosPermisos('000124-7');
        $this->assertTrue(Reserva::notificarCliente(TipoNotificacion::PermisosObtenidos, '000124-7'));
        $this->assertSame(1, DB::table('jobs')->count());

        // Primer intento: falla y vuelve a la cola, disponible en un minuto.
        $this->artisan('queue:work', ['--once' => true]);

        $tarea = DB::table('jobs')->sole();
        $this->assertSame(1, (int) $tarea->attempts);
        $this->assertSame(now()->addSeconds(NotificacionCliente::SEGUNDOS_ENTRE_INTENTOS[0])->getTimestamp(), (int) $tarea->available_at);

        // Segundo intento, pasado el minuto: falla otra vez y espera más.
        $this->travel(NotificacionCliente::SEGUNDOS_ENTRE_INTENTOS[0])->seconds();
        $this->artisan('queue:work', ['--once' => true]);

        $tarea = DB::table('jobs')->sole();
        $this->assertSame(2, (int) $tarea->attempts);
        $this->assertSame(now()->addSeconds(NotificacionCliente::SEGUNDOS_ENTRE_INTENTOS[1])->getTimestamp(), (int) $tarea->available_at);

        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(EstadoReserva::Confirmada, $reserva->fresh()->estado);
    }

    // Si ni siquiera se puede poner el correo en la cola, se anota en el registro y el caso de uso sigue.
    public function test_si_no_se_puede_poner_en_la_cola_no_lanza_y_la_reserva_conserva_su_estado(): void
    {
        $reserva = $this->validarTodosLosPermisos('000124-7');
        config(['queue.default' => 'no-configurada']);
        Log::spy();

        $this->assertFalse(Reserva::notificarCliente(TipoNotificacion::PermisosObtenidos, '000124-7'));

        Log::shouldHaveReceived('error')->once()->withArgs(fn ($mensaje) => str_contains($mensaje, 'Permisos Obtenidos')
            && str_contains($mensaje, '000124-7') && str_contains($mensaje, 'no-configurada'));
        $this->assertSame(EstadoReserva::Confirmada, $reserva->fresh()->estado);
    }

    // Lo que falla puede estar antes de encolar, al juntar los datos: tampoco lanza. Una reserva sin pagos no existe en
    // el sistema (se registra con el primer pago), pero si existiera, «Primera Compra» no tendría comprobante que mostrar.
    public function test_si_falla_al_juntar_los_datos_no_lanza_y_lo_anota(): void
    {
        Mail::fake();
        Log::spy();
        Pago::where('id_reserva', Reserva::buscarReserva('000124-7')->id_reserva)->delete();

        $this->assertFalse(Reserva::notificarCliente(TipoNotificacion::PrimeraCompra, '000124-7'));

        Mail::assertNothingQueued();
        Log::shouldHaveReceived('error')->once()->withArgs(fn ($mensaje) => str_contains($mensaje, 'Primera Compra')
            && str_contains($mensaje, '000124-7'));
    }

    /* -------------------------------- Ayudantes ------------------------------- */

    // El único correo en la cola, del tipo esperado y sólo para el titular.
    private function correoEnviado(TipoNotificacion $tipoNotificacion, string $correoTitular): NotificacionCliente
    {
        Mail::assertQueuedCount(1);
        $correo = Mail::queued(NotificacionCliente::class)->sole();

        $this->assertSame($tipoNotificacion, $correo->tipo);
        $this->assertSame([$correoTitular], array_column($correo->to, 'address'));

        return $correo;
    }

    // El cambio de estado de CU-04, por el modelo: la reserva pasa a «Confirmada».
    private function validarTodosLosPermisos(string $numeroReserva): Reserva
    {
        $reserva = Reserva::buscarReserva($numeroReserva);

        foreach ($reserva->getExcursionistas() as $excursionista) {
            $reserva->registrarPermiso($excursionista->documento_pasaporte, EstadoPermiso::Obtenido);
        }
        $reserva->ejecutarValidacionPermisos();

        $this->assertSame(EstadoReserva::Confirmada, $reserva->fresh()->estado);

        return $reserva;
    }

    private function dolares(float|string $monto): string
    {
        return 'USD '.number_format((float) $monto, 2, ',', '.');
    }
}
