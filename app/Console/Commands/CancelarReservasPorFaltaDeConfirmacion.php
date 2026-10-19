<?php

namespace App\Console\Commands;

use App\Models\Reserva;
use Illuminate\Console\Command;

// Cancelar Reserva por Falta de Confirmación de Permisos (CU-26). Lo dispara el Temporizador (el Schedule de
// routes/console.php), pero también se puede correr a mano para probarlo. Sólo coordina: la regla está en Reserva.
class CancelarReservasPorFaltaDeConfirmacion extends Command
{
    protected $signature = 'reservas:cancelar-por-falta-de-confirmacion';

    protected $description = 'Cancela con reintegro las reservas pendientes o sin permiso cuya fecha límite de confirmación venció (CU-26)';

    public function handle(): int
    {
        $canceladas = Reserva::verificarVencimientosDePermisos(now());

        if ($canceladas === 0) {
            $this->info('No hay reservas con la fecha límite de confirmación vencida.');
        } else {
            $this->info(($canceladas === 1 ? 'Se canceló 1 reserva' : 'Se cancelaron '.$canceladas.' reservas')
                .' por falta de confirmación de permisos.');
        }

        return self::SUCCESS;
    }
}
