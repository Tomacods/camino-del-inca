<?php

namespace App\Filament\Resources\Excursiones\Pages;

use App\Filament\Resources\Excursiones\ExcursionResource;
use Filament\Resources\Pages\ListRecords;

class ListExcursiones extends ListRecords
{
    protected static string $resource = ExcursionResource::class;

    protected static ?string $title = 'Mis excursiones';
}
