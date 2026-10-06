<?php

namespace App\Filament\Resources\Reservas;

use App\Enums\Rol;
use App\Filament\Resources\Reservas\Pages\ListReservas;
use App\Filament\Resources\Reservas\Pages\ViewReserva;
use App\Filament\Resources\Reservas\Schemas\ReservaInfolist;
use App\Filament\Resources\Reservas\Tables\ReservasTable;
use App\Models\Reserva;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ReservaResource extends Resource
{
    protected static ?string $model = Reserva::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'numero_reserva';

    protected static ?string $modelLabel = 'reserva';

    protected static ?string $pluralModelLabel = 'reservas';

    // El guía también entra al panel, pero las reservas son sólo del administrador
    public static function canViewAny(): bool
    {
        return auth()->user()?->rol === Rol::Administrador;
    }

    // Sin esto, un guía podría abrir el detalle escribiendo la dirección a mano
    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function infolist(Schema $schema): Schema
    {
        return ReservaInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReservasTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReservas::route('/'),
            'view' => ViewReserva::route('/{record}'),
        ];
    }
}
