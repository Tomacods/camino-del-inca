<?php

namespace Database\Seeders;

use App\Enums\MotivoDevolucion;
use App\Models\Devolucion;
use App\Models\Reserva;
use Illuminate\Database\Seeder;

class DevolucionSeeder extends Seeder
{
    public function run(): void
    {
        $reservaCancelada = Reserva::where('numero_reserva', '000121-1')->firstOrFail();

        // Reembolso: el 50 % de lo abonado, que fue la seña de USD 240.
        Devolucion::firstOrCreate(
            ['id_reserva' => $reservaCancelada->id_reserva],
            ['fecha' => '2026-09-30', 'monto' => 120, 'motivo' => MotivoDevolucion::Reembolso],
        );
    }
}
