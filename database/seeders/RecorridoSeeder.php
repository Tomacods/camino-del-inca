<?php

namespace Database\Seeders;

use App\Models\Recorrido;
use Illuminate\Database\Seeder;

class RecorridoSeeder extends Seeder
{
    // Los recorridos y sus etapas son datos precargados: el sistema no los crea ni los modifica.
    public function run(): void
    {
        $this->crearRecorrido(
            nombre: 'Camino Inca de 2 días',
            duracionDias: 2,
            cantidadCampings: 0,
            etapas: [
                'Kilómetro 104 (Chachabamba)',
                'Wiñay Wayna',
                'Inti Punku',
                'Machu Picchu',
            ],
        );

        $this->crearRecorrido(
            nombre: 'Camino Inca de 4 días',
            duracionDias: 4,
            cantidadCampings: 3,
            etapas: [
                'Kilómetro 82 (Piscacucho)',
                'Wayllabamba',
                'Abra Warmiwañusca',
                'Pacaymayo',
                'Sayacmarca',
                'Wiñay Wayna',
                'Inti Punku',
                'Machu Picchu',
            ],
        );

        $this->crearRecorrido(
            nombre: 'Camino Inca de 5 días',
            duracionDias: 5,
            cantidadCampings: 4,
            etapas: [
                'Kilómetro 82 (Piscacucho)',
                'Wayllabamba',
                'Ayapata',
                'Abra Warmiwañusca',
                'Pacaymayo',
                'Runkurakay',
                'Sayacmarca',
                'Wiñay Wayna',
                'Inti Punku',
                'Machu Picchu',
            ],
        );
    }

    // Las etapas se numeran en el orden en que aparecen en la lista, empezando por 1.
    private function crearRecorrido(string $nombre, int $duracionDias, int $cantidadCampings, array $etapas): void
    {
        $recorrido = Recorrido::firstOrCreate(
            ['nombre' => $nombre],
            ['duracion_dias' => $duracionDias, 'cantidad_campings' => $cantidadCampings],
        );

        foreach ($etapas as $indice => $nombreEtapa) {
            $recorrido->etapas()->firstOrCreate(
                ['orden' => $indice + 1],
                ['nombre' => $nombreEtapa],
            );
        }
    }
}
