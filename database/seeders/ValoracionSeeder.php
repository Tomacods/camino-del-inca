<?php

namespace Database\Seeders;

use App\Enums\CategoriaValoracion;
use App\Models\Reserva;
use App\Models\Valoracion;
use Illuminate\Database\Seeder;

class ValoracionSeeder extends Seeder
{
    public function run(): void
    {
        $reservaFinalizada = Reserva::where('numero_reserva', '000119-3')->firstOrFail();

        $valoracion = Valoracion::firstOrCreate(
            ['id_reserva' => $reservaFinalizada->id_reserva],
            [
                'fecha' => '2026-09-14 19:30:00',
                'puntaje_experiencia_general' => 4,
                'comentario' => 'Muy buena organización. El guía y los porteadores, excelentes. El tren de vuelta salió con demora.',
            ],
        );

        // Las seis categorías de siempre, más Equipo de Camping porque Carlos Gómez contrató equipo.
        $puntajePorCategoria = [
            CategoriaValoracion::Hotel->value => 4,
            CategoriaValoracion::CampingDeEtapa->value => 4,
            CategoriaValoracion::TransporteEnBus->value => 5,
            CategoriaValoracion::TransporteFerroviario->value => 3,
            CategoriaValoracion::Porteadores->value => 5,
            CategoriaValoracion::Guia->value => 5,
            CategoriaValoracion::EquipoDeCamping->value => 4,
        ];

        // Los detalles se crean siempre a través de la valoración (ver convenciones: clave compuesta).
        foreach ($puntajePorCategoria as $categoria => $puntaje) {
            $valoracion->detalles()->firstOrCreate(['categoria' => $categoria], ['puntaje' => $puntaje]);
        }
    }
}
