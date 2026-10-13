<?php

namespace App\Filament\Resources\Reservas\Pages;

use App\Filament\Resources\Reservas\ReservaResource;
use Filament\Resources\Pages\ViewRecord;

class ViewReserva extends ViewRecord
{
    protected static string $resource = ReservaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ValidarPermisosReserva::accionValidarPermisos(),
        ];
    }
}
