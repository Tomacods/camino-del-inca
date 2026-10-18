<?php

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

// CU-22. La fecha límite del saldo vence a las 23:59:59: se verifica una vez por día, apenas pasada la medianoche.
Schedule::command(CancelarReservasPorFaltaDePago::class)
    ->dailyAt(config('reserva.hora_verificacion_saldos_vencidos'))
    ->withoutOverlapping();
