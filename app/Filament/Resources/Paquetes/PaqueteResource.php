<?php

namespace App\Filament\Resources\Paquetes;

use App\Enums\Rol;
use App\Filament\Resources\Paquetes\Pages\CreatePaquete;
use App\Filament\Resources\Paquetes\Pages\ListPaquetes;
use App\Filament\Resources\Paquetes\Schemas\PaqueteForm;
use App\Filament\Resources\Paquetes\Tables\PaquetesTable;
use App\Models\Paquete;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

// El catálogo de paquetes del administrador: Crear Paquete (CU-01). Modificar (CU-02) y Eliminar (CU-03) se suman acá.
class PaqueteResource extends Resource
{
    protected static ?string $model = Paquete::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAmericas;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $modelLabel = 'paquete';

    protected static ?string $pluralModelLabel = 'paquetes';

    protected static ?string $slug = 'paquetes';

    // El guía también entra al panel, pero los paquetes los administra sólo el administrador
    public static function canViewAny(): bool
    {
        return auth()->user()?->rol === Rol::Administrador;
    }

    // Sin esto, un guía podría abrir el formulario escribiendo la dirección a mano
    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return PaqueteForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaquetesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaquetes::route('/'),
            'create' => CreatePaquete::route('/crear'),
        ];
    }
}
