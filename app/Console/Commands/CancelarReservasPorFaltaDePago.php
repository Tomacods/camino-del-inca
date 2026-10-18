<?php

namespace App\Console\Commands;

use App\Models\Reserva;
use Illuminate\Console\Command;

// Cancelar Reserva por Falta de Pago de Saldo (CU-22). Lo dispara el Temporizador (el Schedule de routes/console.php),
// pero también se puede correr a mano para probarlo. Sólo coordina: la regla está en Reserva.
class CancelarReservasPorFaltaDePago extends Command
{
    protected $signature = 'reservas:cancelar-por-falta-de-pago';

    protected $description = 'Cancela las reservas confirmadas cuyo saldo no se pagó antes de la fecha límite (CU-22)';

    public function handle(): int
    {
        $canceladas = Reserva::verificarVencimientosDePago(now());

        if ($canceladas === 0) {
            $this->info('No hay reservas con el saldo vencido.');
        } else {
            $this->info('Se cancelaron '.$canceladas.' '.($canceladas === 1 ? 'reserva' : 'reservas').' por falta de pago del saldo.');
        }

        return self::SUCCESS;
    }
}
