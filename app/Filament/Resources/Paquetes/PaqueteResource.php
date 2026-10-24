<?php

namespace App\Filament\Resources\Paquetes;

use App\Enums\Rol;
use App\Filament\Resources\Paquetes\Pages\CreatePaquete;
use App\Filament\Resources\Paquetes\Pages\EditPaquete;
use App\Filament\Resources\Paquetes\Pages\ListPaquetes;
use App\Filament\Resources\Paquetes\Pages\ViewPaquete;
use App\Filament\Resources\Paquetes\Schemas\PaqueteForm;
use App\Filament\Resources\Paquetes\Schemas\PaqueteInfolist;
use App\Filament\Resources\Paquetes\Tables\PaquetesTable;
use App\Models\Paquete;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PaqueteResource extends Resource
{
    protected static ?string $model = Paquete::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAmericas;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $modelLabel = 'paquete';

    protected static ?string $pluralModelLabel = 'paquetes';

    protected static ?string $slug = 'paquetes';

    public static function canViewAny(): bool
    {
        return auth()->user()?->rol === Rol::Administrador;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    // Si tiene reservas activas, la página de edición avisa y vuelve al listado (R-02): acá sólo se controla el rol.
    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return PaqueteForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PaqueteInfolist::configure($schema);
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
            'view' => ViewPaquete::route('/{record}'),
            'edit' => EditPaquete::route('/{record}/modificar'),
        ];
    }
}
