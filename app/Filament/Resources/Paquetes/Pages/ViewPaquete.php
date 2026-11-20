<?php

namespace App\Filament\Resources\Paquetes\Pages;

use App\Filament\Resources\Paquetes\Actions\AgregarExcursion;
use App\Filament\Resources\Paquetes\PaqueteResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPaquete extends ViewRecord
{
    protected static string $resource = PaqueteResource::class;

    // CU-27: desde la consulta del paquete se le puede agregar una salida, aunque tenga reservas.
    protected function getHeaderActions(): array
    {
        return [
            AgregarExcursion::accionAgregarExcursion(),
        ];
    }
}
