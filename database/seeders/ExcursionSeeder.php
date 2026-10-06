<?php

namespace Database\Seeders;

use App\Models\Excursion;
use App\Models\Paquete;
use App\Models\Usuario;
use Illuminate\Database\Seeder;

class ExcursionSeeder extends Seeder
{
    // Todas las fechas de salida son lunes. Cada salida lleva el correo de la cuenta del guía asignado.
    public function run(): void
    {
        $this->crearSalidaRealizada(
            nombrePaquete: 'Camino Inca Clásico',
            fechaSalida: '2026-09-07',
            correoGuia: 'guia@caminodelinca.test',
            cupo: 12,
            fechaHoraInicioRecorrido: '2026-09-07 06:00:00',
        );

        $this->crearSalidas(nombrePaquete: 'Camino Inca Clásico', cupo: 12, guiaPorFechaSalida: [
            '2026-12-14' => 'luko@caminodelinca.test',
            '2027-01-04' => 'ojosverdes@caminodelinca.test',
            '2027-01-18' => 'guia@caminodelinca.test',
            '2027-02-01' => 'luko@caminodelinca.test',
            '2027-02-15' => 'ojosverdes@caminodelinca.test',
            '2027-03-01' => 'guia@caminodelinca.test',
            '2027-03-15' => 'luko@caminodelinca.test',
        ]);

        $this->crearSalidas(nombrePaquete: 'Camino Inca Corto', cupo: 10, guiaPorFechaSalida: [
            '2027-01-11' => 'ojosverdes@caminodelinca.test',
            '2027-02-08' => 'guia@caminodelinca.test',
        ]);

        $this->crearSalidas(nombrePaquete: 'Camino Inca Extendido', cupo: 8, guiaPorFechaSalida: [
            '2027-01-25' => 'luko@caminodelinca.test',
            '2027-02-22' => 'ojosverdes@caminodelinca.test',
        ]);
    }

    private function crearSalidas(string $nombrePaquete, int $cupo, array $guiaPorFechaSalida): void
    {
        $paquete = Paquete::where('nombre', $nombrePaquete)->firstOrFail();

        foreach ($guiaPorFechaSalida as $fechaSalida => $correoGuia) {
            Excursion::firstOrCreate(
                ['id_paquete' => $paquete->id_paquete, 'fecha_salida' => $fechaSalida],
                ['id_guia' => $this->idGuia($correoGuia), 'cupo' => $cupo],
            );
        }
    }

    // Una salida que ya terminó: el guía inició el recorrido y reportó hasta la última etapa.
    private function crearSalidaRealizada(
        string $nombrePaquete,
        string $fechaSalida,
        string $correoGuia,
        int $cupo,
        string $fechaHoraInicioRecorrido,
    ): void {
        $paquete = Paquete::where('nombre', $nombrePaquete)->firstOrFail();
        $ultimaEtapa = $paquete->recorrido->etapas()->orderByDesc('orden')->firstOrFail();

        Excursion::firstOrCreate(
            ['id_paquete' => $paquete->id_paquete, 'fecha_salida' => $fechaSalida],
            [
                'id_guia' => $this->idGuia($correoGuia),
                'cupo' => $cupo,
                'fecha_hora_inicio_recorrido' => $fechaHoraInicioRecorrido,
                'id_etapa_actual' => $ultimaEtapa->id_etapa,
            ],
        );
    }

    // La clave del guía es la de su cuenta de usuario.
    private function idGuia(string $correo): int
    {
        return Usuario::where('correo', $correo)->firstOrFail()->id_usuario;
    }
}
