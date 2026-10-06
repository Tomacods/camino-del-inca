<?php

namespace Database\Seeders;

use App\Models\Paquete;
use App\Models\Recorrido;
use App\Models\Servicio;
use Illuminate\Database\Seeder;

class PaqueteSeeder extends Seeder
{
    private const COSTO_NOCHE_EXTRA_CUSCO = 60;

    private const COSTO_EQUIPO_CAMPING = 45;

    public function run(): void
    {
        $this->crearPaquete(
            nombre: 'Camino Inca Clásico',
            nombreRecorrido: 'Camino Inca de 4 días',
            precioBase: 750,
            cantidadPorteadores: 6,
            nombresServicios: [
                'Hotel en Cusco',
                'Bus de Cusco al Kilómetro 82',
                'Camping Wayllabamba',
                'Camping Pacaymayo',
                'Camping Wiñay Wayna',
                'Bus de Machu Picchu a Aguas Calientes',
                'Tren de Aguas Calientes a Ollantaytambo',
                'Bus de Ollantaytambo a Cusco',
            ],
        );

        $this->crearPaquete(
            nombre: 'Camino Inca Corto',
            nombreRecorrido: 'Camino Inca de 2 días',
            precioBase: 480,
            cantidadPorteadores: 0,
            nombresServicios: [
                'Tren de Ollantaytambo al Kilómetro 104',
                'Bus de Machu Picchu a Aguas Calientes',
                'Hotel en Aguas Calientes',
                'Tren de Aguas Calientes a Ollantaytambo',
                'Bus de Ollantaytambo a Cusco',
            ],
        );

        $this->crearPaquete(
            nombre: 'Camino Inca Extendido',
            nombreRecorrido: 'Camino Inca de 5 días',
            precioBase: 950,
            cantidadPorteadores: 8,
            nombresServicios: [
                'Hotel en Cusco',
                'Bus de Cusco al Kilómetro 82',
                'Camping Wayllabamba',
                'Camping Ayapata',
                'Camping Pacaymayo',
                'Camping Wiñay Wayna',
                'Bus de Machu Picchu a Aguas Calientes',
                'Tren de Aguas Calientes a Ollantaytambo',
                'Bus de Ollantaytambo a Cusco',
            ],
        );
    }

    private function crearPaquete(
        string $nombre,
        string $nombreRecorrido,
        int $precioBase,
        int $cantidadPorteadores,
        array $nombresServicios,
    ): void {
        $paquete = Paquete::firstOrCreate(
            ['nombre' => $nombre],
            [
                'id_recorrido' => Recorrido::where('nombre', $nombreRecorrido)->firstOrFail()->id_recorrido,
                'precio_base' => $precioBase,
                'costo_noche_extra_cusco' => self::COSTO_NOCHE_EXTRA_CUSCO,
                'costo_equipo_camping' => self::COSTO_EQUIPO_CAMPING,
                'cantidad_porteadores' => $cantidadPorteadores,
                'estado' => Paquete::ESTADO_ACTIVO,
                'fecha_creacion' => today(),
            ],
        );

        // paquete_servicio no tiene modelo, así que no hay firstOrCreate: syncWithoutDetaching agrega los servicios que
        // falten y deja los que ya estaban, y logra lo mismo.
        $paquete->servicios()->syncWithoutDetaching(
            Servicio::whereIn('nombre', $nombresServicios)->pluck('id_servicio'),
        );
    }
}
