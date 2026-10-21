<?php

use App\Console\Commands\CancelarReservasPorFaltaDeConfirmacion;
use App\Console\Commands\CancelarReservasPorFaltaDePago;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/* ------------------------- Temporizador (tareas programadas) ------------------------- */

// Corre con `php artisan schedule:work` (desarrollo) o con `schedule:run` cada minuto (servidor), en la zona horaria de
// config/app.php. Cada cancelación automática es un comando propio, así se puede correr a mano. withoutOverlapping():
// si una corrida tarda, la siguiente no arranca encima.

// CU-22. Se verifica cada hora: la fecha límite del saldo vence a las 23:59:59, salvo la de una reserva que quedó
// «Confirmada» con el saldo adeudado el último día, que vence a las 72 horas de la notificación, a cualquier hora. Así
// una reserva se cancela a lo sumo una hora después de vencer; las del día límite, a las 00:05.
Schedule::command(CancelarReservasPorFaltaDePago::class)
    ->hourlyAt(config('reserva.minuto_verificacion_saldos_vencidos'))
    ->withoutOverlapping();

// CU-26. Se verifica cada hora: la fecha límite de confirmación vence a las 23:59:59, salvo la de una reserva que pasó a
// «Sin Permiso» el último día, que vence a las 72 horas de la notificación, a cualquier hora. Así una reserva se cancela
// a lo sumo una hora después de vencer; las del día límite, a las 00:05. Puede correr a la misma hora que CU-22 porque
// no se cruzan: CU-22 cancela reservas «Confirmada», y CU-26, «Pendiente» o «Sin Permiso».
Schedule::command(CancelarReservasPorFaltaDeConfirmacion::class)
    ->hourlyAt(config('reserva.minuto_verificacion_confirmaciones_vencidas'))
    ->withoutOverlapping();
