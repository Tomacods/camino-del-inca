<?php

namespace App\Filament\Resources\Excursiones;

use App\Enums\Rol;
use App\Filament\Resources\Excursiones\Pages\ListarIntegrantesExcursion;
use App\Filament\Resources\Excursiones\Pages\ListExcursiones;
use App\Filament\Resources\Excursiones\Tables\ExcursionesTable;
use App\Models\Excursion;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

// Las excursiones del guía: Listar Integrantes (CU-10) y, más adelante, Iniciar Recorrido (CU-11) y Reportar Etapa
// (CU-12).
class ExcursionResource extends Resource
{
    protected static ?string $model = Excursion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?string $navigationLabel = 'Mis excursiones';

    protected static ?string $modelLabel = 'excursión';

    protected static ?string $pluralModelLabel = 'excursiones';

    // Filament armaría la dirección en inglés («excursions»).
    protected static ?string $slug = 'excursiones';

    // El administrador también entra al panel, pero las excursiones de este menú son las del guía
    public static function canViewAny(): bool
    {
        return auth()->user()?->rol === Rol::Guia;
    }

    // Cada guía ve sólo las excursiones a su cargo. Las páginas del recurso buscan la excursión con esta consulta, así
    // que la de otro guía no se encuentra aunque se escriba la dirección a mano.
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->deGuia((int) auth()->id());
    }

    public static function table(Table $table): Table
    {
        return ExcursionesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExcursiones::route('/'),
            'integrantes' => ListarIntegrantesExcursion::route('/{record}/integrantes'),
        ];
    }
}
