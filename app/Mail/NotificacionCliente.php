<?php

namespace App\Mail;

use App\Enums\TipoNotificacion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// El correo de Notificar Cliente (CU-13): el ServicioCorreo del DS-13 es el Mail de Laravel. Lo arma
// Reserva::armarMensaje() y sale por la cola, fuera del pedido del cliente o del administrador. Se encola en el momento:
// por eso Reserva::notificarCliente() se invoca después de la transacción del caso de uso, y si falla al encolar, lo
// anota sin romper nada (A8). Con la cola en la base (QUEUE_CONNECTION=database), el trabajo se guarda en la misma
// conexión: si alguien lo invocara dentro de la transacción y ésta se deshiciera, el correo tampoco quedaría.
class NotificacionCliente extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    // A8: si el servicio de correo falla, la cola lo vuelve a intentar hasta completar INTENTOS. Espera 1 minuto antes
    // del primer reintento, 5 antes del segundo y 15 antes de los siguientes. Si el último también falla, queda en
    // failed_jobs. El cambio de estado de la reserva ya se guardó antes: el correo no lo revierte ni lo bloquea.
    public const INTENTOS = 5;

    public const SEGUNDOS_ENTRE_INTENTOS = [60, 300, 900];

    public $tries = self::INTENTOS;

    public $backoff = self::SEGUNDOS_ENTRE_INTENTOS;

    // Los datos llegan ya leídos y calculados por Reserva::notificar(): si el correo sale en un reintento, dice lo mismo
    // que en el momento en que se invocó.
    public function __construct(
        public TipoNotificacion $tipo,
        public array $datos,
    ) {}

    public function envelope(): Envelope
    {
        $numeroReserva = $this->datos['reserva']['numero_reserva'];

        return new Envelope(
            subject: match ($this->tipo) {
                TipoNotificacion::PrimeraCompra => 'Tu reserva '.$numeroReserva.' está registrada',
                TipoNotificacion::PagoSaldo => 'Recibimos el pago del saldo de tu reserva '.$numeroReserva,
                TipoNotificacion::CancelacionReserva => 'Tu reserva '.$numeroReserva.' fue cancelada',
                TipoNotificacion::PermisosObtenidos => 'Tu reserva '.$numeroReserva.' está confirmada',
                TipoNotificacion::PermisosRechazados => 'Tu reserva '.$numeroReserva.' quedó sin permiso',
                TipoNotificacion::CancelacionFaltaPago => 'Tu reserva '.$numeroReserva.' fue cancelada por falta de pago',
                TipoNotificacion::EtapaFinal => 'Tu excursión terminó: contanos cómo te fue',
                TipoNotificacion::CancelacionFaltaPermisos => 'Tu reserva '.$numeroReserva.' fue cancelada por falta de permisos',
            },
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'correos.notificacion-cliente',
        );
    }
}
