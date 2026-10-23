<?php

namespace App\Filament\Resources\Paquetes\Pages;

use App\Filament\Resources\Paquetes\PaqueteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPaquetes extends ListRecords
{
    protected static string $resource = PaqueteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Crear paquete'),
        ];
    }
}
