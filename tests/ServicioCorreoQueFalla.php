<?php

namespace Tests;

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

// Ocupa el lugar del servicio de correo en las pruebas de A8 de Notificar Cliente (CU-13): cada envío falla como falla
// un servidor de correo que no responde. Así se prueba que la reserva no pierde su cambio de estado y que la cola
// reintenta el envío.
class ServicioCorreoQueFalla extends AbstractTransport
{
    public const MENSAJE = 'El servidor de correo no responde.';

    // Lo pone como servicio de correo por defecto. Se llama en la prueba, antes de enviar.
    public static function instalar(): void
    {
        Mail::extend('que-falla', fn () => new self);

        config([
            'mail.mailers.que-falla' => ['transport' => 'que-falla'],
            'mail.default' => 'que-falla',
        ]);
    }

    protected function doSend(SentMessage $message): void
    {
        throw new TransportException(self::MENSAJE);
    }

    public function __toString(): string
    {
        return 'que-falla://';
    }
}
