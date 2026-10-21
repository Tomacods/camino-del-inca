<?php

namespace App\Filament\Resources\Paquetes\Tables;

use App\Enums\EstadoPaquete;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PaquetesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('fecha_creacion', 'desc')
            ->columns([
                TextColumn::make('nombre')
                    ->label('Nombre')
                    ->searchable(),
                TextColumn::make('recorrido.nombre')
                    ->label('Recorrido'),
                TextColumn::make('precio_base')
                    ->label('Precio base')
                    ->money('USD'),
                TextColumn::make('excursiones_count')
                    ->label('Excursiones')
                    ->counts('excursiones'),
                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state->value),
                TextColumn::make('fecha_creacion')
                    ->label('Creado')
                    ->date('d/m/Y'),
            ])
            ->filters([
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(collect(EstadoPaquete::cases())
                        ->mapWithKeys(fn (EstadoPaquete $estado) => [$estado->value => $estado->value])
                        ->all()),
            ]);
    }
}
