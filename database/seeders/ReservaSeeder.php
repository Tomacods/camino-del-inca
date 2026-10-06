<?php

namespace Database\Seeders;

use App\Models\Excursionista;
use App\Models\Paquete;
use App\Models\Reserva;
use Illuminate\Database\Seeder;

class ReservaSeeder extends Seeder
{
    // Al menos una reserva en cada estado, todas con 3 meses de anticipación o más. Los números se escriben a mano, en
    // el orden de fecha_reserva (el dígito verificador es módulo 11 con pesos 2 a 7; 000120 se saltea porque su resto
    // es 10). Las fechas límite vencen a las 23:59:59 del día, un mes antes de la salida, como en el prototipo.
    public function run(): void
    {
        // Se abonó el total al reservar: no tiene fecha límite de saldo.
        $this->crearReserva('Camino Inca Clásico', '2026-09-07', [
            'numero_reserva' => '000119-3',
            'correo_electronico' => 'carlos.gomez@mail.com',
            'fecha_reserva' => '2026-06-01 11:20:00',
            'estado' => Reserva::ESTADO_FINALIZADA,
            'estado_saldo' => Reserva::ESTADO_SALDO_ABONADO,
            'noches_extra_antes' => 0,
            'noches_extra_despues' => 0,
            'fecha_limite_saldo' => null,
            'fecha_limite_confirmacion' => '2026-08-07 23:59:59',
        ], [
            ['nombre' => 'Carlos', 'apellido' => 'Gómez', 'documento_pasaporte' => 'AAC118204', 'equipo_camping' => true, 'estado_permiso' => Excursionista::ESTADO_PERMISO_OBTENIDO],
            ['nombre' => 'Ana', 'apellido' => 'Gómez', 'documento_pasaporte' => 'AAC118205', 'equipo_camping' => false, 'estado_permiso' => Excursionista::ESTADO_PERMISO_OBTENIDO],
        ]);

        $this->crearReserva('Camino Inca Corto', '2027-01-11', [
            'numero_reserva' => '000121-1',
            'correo_electronico' => 'martina.lopez@mail.com',
            'fecha_reserva' => '2026-09-05 14:05:00',
            'estado' => Reserva::ESTADO_CANCELADA,
            'estado_saldo' => Reserva::ESTADO_SALDO_ADEUDADO,
            'noches_extra_antes' => 0,
            'noches_extra_despues' => 0,
            'fecha_limite_saldo' => '2026-12-11 23:59:59',
            'fecha_limite_confirmacion' => '2026-12-11 23:59:59',
        ], [
            ['nombre' => 'Martina', 'apellido' => 'López', 'documento_pasaporte' => 'AAD330917', 'equipo_camping' => false, 'estado_permiso' => Excursionista::ESTADO_PERMISO_PENDIENTE],
        ]);

        // Se abonó el total al reservar: no tiene fecha límite de saldo.
        $this->crearReserva('Camino Inca Clásico', '2026-12-14', [
            'numero_reserva' => '000122-3',
            'correo_electronico' => 'valentina.diaz@mail.com',
            'fecha_reserva' => '2026-09-10 16:45:00',
            'estado' => Reserva::ESTADO_CONFIRMADA,
            'estado_saldo' => Reserva::ESTADO_SALDO_ABONADO,
            'noches_extra_antes' => 1,
            'noches_extra_despues' => 0,
            'fecha_limite_saldo' => null,
            'fecha_limite_confirmacion' => '2026-11-14 23:59:59',
        ], [
            ['nombre' => 'Valentina', 'apellido' => 'Díaz', 'documento_pasaporte' => 'AAE402215', 'equipo_camping' => true, 'estado_permiso' => Excursionista::ESTADO_PERMISO_OBTENIDO],
            ['nombre' => 'Joaquín', 'apellido' => 'Pereyra', 'documento_pasaporte' => 'AAE402388', 'equipo_camping' => true, 'estado_permiso' => Excursionista::ESTADO_PERMISO_OBTENIDO],
        ]);

        $this->crearReserva('Camino Inca Clásico', '2027-01-04', [
            'numero_reserva' => '000123-5',
            'correo_electronico' => 'diego.morales@mail.com',
            'fecha_reserva' => '2026-09-28 09:10:00',
            'estado' => Reserva::ESTADO_SIN_PERMISO,
            'estado_saldo' => Reserva::ESTADO_SALDO_ADEUDADO,
            'noches_extra_antes' => 0,
            'noches_extra_despues' => 2,
            'fecha_limite_saldo' => '2026-12-04 23:59:59',
            'fecha_limite_confirmacion' => '2026-12-04 23:59:59',
        ], [
            ['nombre' => 'Diego', 'apellido' => 'Morales', 'documento_pasaporte' => 'AAF517402', 'equipo_camping' => false, 'estado_permiso' => Excursionista::ESTADO_PERMISO_OBTENIDO],
            ['nombre' => 'Camila', 'apellido' => 'Morales', 'documento_pasaporte' => 'AAF517403', 'equipo_camping' => false, 'estado_permiso' => Excursionista::ESTADO_PERMISO_NO_OBTENIDO],
        ]);

        // La reserva del prototipo.
        $this->crearReserva('Camino Inca Clásico', '2027-01-18', [
            'numero_reserva' => '000124-7',
            'correo_electronico' => 'lucia.fernandez@mail.com',
            'fecha_reserva' => '2026-09-30 10:30:00',
            'estado' => Reserva::ESTADO_PENDIENTE,
            'estado_saldo' => Reserva::ESTADO_SALDO_ADEUDADO,
            'noches_extra_antes' => 1,
            'noches_extra_despues' => 1,
            'fecha_limite_saldo' => '2026-12-18 23:59:59',
            'fecha_limite_confirmacion' => '2026-12-18 23:59:59',
        ], [
            ['nombre' => 'Lucía', 'apellido' => 'Fernández', 'documento_pasaporte' => 'AAG604118', 'equipo_camping' => true, 'estado_permiso' => Excursionista::ESTADO_PERMISO_PENDIENTE],
            ['nombre' => 'Martín', 'apellido' => 'Fernández', 'documento_pasaporte' => 'AAG604119', 'equipo_camping' => true, 'estado_permiso' => Excursionista::ESTADO_PERMISO_PENDIENTE],
            ['nombre' => 'Sofía', 'apellido' => 'Ruiz', 'documento_pasaporte' => 'AAG611025', 'equipo_camping' => false, 'estado_permiso' => Excursionista::ESTADO_PERMISO_PENDIENTE],
        ]);

        // Confirmada con el saldo todavía adeudado.
        $this->crearReserva('Camino Inca Clásico', '2027-02-01', [
            'numero_reserva' => '000125-9',
            'correo_electronico' => 'federico.rios@mail.com',
            'fecha_reserva' => '2026-10-01 18:20:00',
            'estado' => Reserva::ESTADO_CONFIRMADA,
            'estado_saldo' => Reserva::ESTADO_SALDO_ADEUDADO,
            'noches_extra_antes' => 0,
            'noches_extra_despues' => 0,
            'fecha_limite_saldo' => '2027-01-01 23:59:59',
            'fecha_limite_confirmacion' => '2027-01-01 23:59:59',
        ], [
            ['nombre' => 'Federico', 'apellido' => 'Ríos', 'documento_pasaporte' => 'AAH715300', 'equipo_camping' => false, 'estado_permiso' => Excursionista::ESTADO_PERMISO_OBTENIDO],
            ['nombre' => 'Lorena', 'apellido' => 'Ríos', 'documento_pasaporte' => 'AAH715301', 'equipo_camping' => false, 'estado_permiso' => Excursionista::ESTADO_PERMISO_OBTENIDO],
        ]);
    }

    private function crearReserva(string $nombrePaquete, string $fechaSalida, array $datosReserva, array $excursionistas): void
    {
        $excursion = Paquete::where('nombre', $nombrePaquete)->firstOrFail()
            ->excursiones()->where('fecha_salida', $fechaSalida)->firstOrFail();

        $reserva = Reserva::firstOrCreate(
            ['numero_reserva' => $datosReserva['numero_reserva']],
            ['id_excursion' => $excursion->id_excursion] + $datosReserva,
        );

        foreach ($excursionistas as $excursionista) {
            $reserva->excursionistas()->firstOrCreate(
                ['documento_pasaporte' => $excursionista['documento_pasaporte']],
                $excursionista,
            );
        }
    }
}
