<?php

namespace Database\Seeders;

use App\Models\DetalleValoracion;
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
            DetalleValoracion::CATEGORIA_HOTEL => 4,
            DetalleValoracion::CATEGORIA_CAMPING_DE_ETAPA => 4,
            DetalleValoracion::CATEGORIA_TRANSPORTE_EN_BUS => 5,
            DetalleValoracion::CATEGORIA_TRANSPORTE_FERROVIARIO => 3,
            DetalleValoracion::CATEGORIA_PORTEADORES => 5,
            DetalleValoracion::CATEGORIA_GUIA => 5,
            DetalleValoracion::CATEGORIA_EQUIPO_DE_CAMPING => 4,
        ];

        // Los detalles se crean siempre a través de la valoración (ver convenciones: clave compuesta).
        foreach ($puntajePorCategoria as $categoria => $puntaje) {
            $valoracion->detalles()->firstOrCreate(['categoria' => $categoria], ['puntaje' => $puntaje]);
        }
    }
}
