<?php

namespace Database\Seeders;

use App\Enums\TipoServicio;
use App\Models\Servicio;
use Illuminate\Database\Seeder;

class ServicioSeeder extends Seeder
{
    // Los servicios son datos precargados.
    public function run(): void
    {
        $nombresPorTipo = [
            TipoServicio::Hotel->value => [
                'Hotel en Cusco',
                'Hotel en Aguas Calientes',
            ],
            TipoServicio::TransporteEnBus->value => [
                'Bus de Cusco al Kilómetro 82',
                'Bus de Machu Picchu a Aguas Calientes',
                'Bus de Ollantaytambo a Cusco',
            ],
            TipoServicio::TransporteFerroviario->value => [
                'Tren de Ollantaytambo al Kilómetro 104',
                'Tren de Aguas Calientes a Ollantaytambo',
            ],
            TipoServicio::CampingDeEtapa->value => [
                'Camping Wayllabamba',
                'Camping Ayapata',
                'Camping Pacaymayo',
                'Camping Wiñay Wayna',
            ],
        ];

        foreach ($nombresPorTipo as $tipo => $nombres) {
            foreach ($nombres as $nombre) {
                Servicio::firstOrCreate(['tipo' => $tipo, 'nombre' => $nombre]);
            }
        }
    }
}
