<?php

namespace App\Filament\Resources\Valoraciones\Pages;

use App\Filament\Resources\Valoraciones\ValoracionResource;
use Filament\Resources\Pages\ListRecords;

// CU-08 Listar Valoraciones
class ListValoraciones extends ListRecords
{
    protected static string $resource = ValoracionResource::class;
}
