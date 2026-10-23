<?php

namespace App\Filament\Resources\Paquetes\Tables;

use App\Enums\EstadoPaquete;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PaquetesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre')->label('Nombre')->searchable(),
                TextColumn::make('recorrido.nombre')->label('Recorrido'),
                TextColumn::make('precio_base')->label('Precio base')->money('USD'),
                TextColumn::make('excursiones_count')->label('Excursiones')->counts('excursiones'),
                TextColumn::make('estado')->label('Estado')->badge()
                    ->formatStateUsing(fn (EstadoPaquete $state) => $state->value),
                TextColumn::make('fecha_creacion')->label('Creación')->date('d/m/Y'),
            ])
            ->defaultSort('fecha_creacion', 'desc')
            ->filters([
                SelectFilter::make('estado')->label('Estado')->options([
                    EstadoPaquete::Activo->value => EstadoPaquete::Activo->value,
                    EstadoPaquete::Inactivo->value => EstadoPaquete::Inactivo->value,
                ]),
            ])
            ->recordActions([
                ViewAction::make()->label('Ver'),
            ]);
    }
}
