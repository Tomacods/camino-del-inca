<?php

namespace Database\Seeders;

use App\Models\Comprobante;
use App\Models\Pago;
use App\Models\Reserva;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class PagoSeeder extends Seeder
{
    // Cada pago tiene su comprobante, emitido en el momento del pago; para el primer pago, ese momento es la
    // fecha_reserva. Los montos: precio base y noches extra (USD 60) por persona, más USD 45 por equipo de camping;
    // la seña es el 50 %. Con esa cuenta da la seña del prototipo (000124-7).
    public function run(): void
    {
        // Finalizada: 2 × 750 + 1 equipo × 45.
        $this->crearPago(
            numeroReserva: '000119-3',
            tipoPago: Pago::TIPO_PAGO_TOTAL,
            monto: 1545,
            medioPago: 'Tarjeta de crédito',
            fechaHoraPago: '2026-06-01 11:20:00',
            numeroComprobante: '0001-00000001',
        );

        // Cancelada: seña de 1 × 480.
        $this->crearPago(
            numeroReserva: '000121-1',
            tipoPago: Pago::TIPO_PAGO_SENA,
            monto: 240,
            medioPago: 'Tarjeta de débito',
            fechaHoraPago: '2026-09-05 14:05:00',
            numeroComprobante: '0001-00000002',
        );

        // Confirmada: 2 × 750 + 1 noche × 2 × 60 + 2 equipos × 45.
        $this->crearPago(
            numeroReserva: '000122-3',
            tipoPago: Pago::TIPO_PAGO_TOTAL,
            monto: 1710,
            medioPago: 'Dinero en cuenta de Mercado Pago',
            fechaHoraPago: '2026-09-10 16:45:00',
            numeroComprobante: '0001-00000003',
        );

        // Sin Permiso: seña de 2 × 750 + 2 noches × 2 × 60.
        $this->crearPago(
            numeroReserva: '000123-5',
            tipoPago: Pago::TIPO_PAGO_SENA,
            monto: 870,
            medioPago: 'Tarjeta de crédito',
            fechaHoraPago: '2026-09-28 09:10:00',
            numeroComprobante: '0001-00000004',
        );

        // La del prototipo: seña de 3 × 750 + 2 noches × 3 × 60 + 2 equipos × 45.
        $this->crearPago(
            numeroReserva: '000124-7',
            tipoPago: Pago::TIPO_PAGO_SENA,
            monto: 1350,
            medioPago: 'Tarjeta de crédito',
            fechaHoraPago: '2026-09-30 10:30:00',
            numeroComprobante: '0001-00000005',
        );

        // Confirmada con saldo adeudado: seña de 2 × 750.
        $this->crearPago(
            numeroReserva: '000125-9',
            tipoPago: Pago::TIPO_PAGO_SENA,
            monto: 750,
            medioPago: 'Tarjeta de crédito',
            fechaHoraPago: '2026-10-01 18:20:00',
            numeroComprobante: '0001-00000006',
        );
    }

    private function crearPago(
        string $numeroReserva,
        string $tipoPago,
        int $monto,
        string $medioPago,
        string $fechaHoraPago,
        string $numeroComprobante,
    ): void {
        $reserva = Reserva::where('numero_reserva', $numeroReserva)->firstOrFail();

        $pago = Pago::firstOrCreate(
            ['id_reserva' => $reserva->id_reserva, 'tipo_pago' => $tipoPago],
            ['fecha' => Carbon::parse($fechaHoraPago)->toDateString(), 'monto' => $monto, 'medio_pago' => $medioPago],
        );

        Comprobante::firstOrCreate(
            ['id_pago' => $pago->id_pago],
            ['numero_comprobante' => $numeroComprobante, 'fecha_emision' => $fechaHoraPago],
        );
    }
}
