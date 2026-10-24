<?php

namespace App\Filament\Resources\Valoraciones;

use App\Enums\Rol;
use App\Filament\Resources\Valoraciones\Pages\ListValoraciones;
use App\Filament\Resources\Valoraciones\Pages\ViewValoracion;
use App\Filament\Resources\Valoraciones\Schemas\ValoracionInfolist;
use App\Filament\Resources\Valoraciones\Tables\ValoracionesTable;
use App\Models\Valoracion;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

// Las valoraciones que cargan los clientes (CU-23): Listar Valoraciones (CU-08) y Consultar Valoración (CU-09). Sólo se
// leen: el administrador no crea, modifica ni borra valoraciones.
class ValoracionResource extends Resource
{
    protected static ?string $model = Valoracion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static ?string $modelLabel = 'valoración';

    protected static ?string $pluralModelLabel = 'valoraciones';

    protected static ?string $slug = 'valoraciones';

    // El guía también entra al panel, pero las valoraciones las consulta sólo el administrador
    public static function canViewAny(): bool
    {
        return auth()->user()?->rol === Rol::Administrador;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return ValoracionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ValoracionesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListValoraciones::route('/'),
            'view' => ViewValoracion::route('/{record}'),
        ];
    }
}
