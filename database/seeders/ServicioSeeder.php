<?php

namespace Database\Seeders;

use App\Models\Servicio;
use Illuminate\Database\Seeder;

class ServicioSeeder extends Seeder
{
    // Los servicios son datos precargados.
    public function run(): void
    {
        $nombresPorTipo = [
            Servicio::TIPO_HOTEL => [
                'Hotel en Cusco',
                'Hotel en Aguas Calientes',
            ],
            Servicio::TIPO_TRANSPORTE_EN_BUS => [
                'Bus de Cusco al Kilómetro 82',
                'Bus de Machu Picchu a Aguas Calientes',
                'Bus de Ollantaytambo a Cusco',
            ],
            Servicio::TIPO_TRANSPORTE_FERROVIARIO => [
                'Tren de Ollantaytambo al Kilómetro 104',
                'Tren de Aguas Calientes a Ollantaytambo',
            ],
            Servicio::TIPO_CAMPING_DE_ETAPA => [
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
