<?php

namespace App\Filament\Resources\Paquetes\Tables;

use App\Enums\EstadoPaquete;
use App\Filament\Resources\Paquetes\Actions\DarDeBajaPaquete;
use App\Filament\Resources\Paquetes\Actions\ReactivarPaquete;
use App\Models\Paquete;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

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
                // R-05: el administrador filtra también por fecha de salida, como el cliente en el portal.
                Filter::make('fecha_salida')
                    ->schema([
                        DatePicker::make('fecha_salida')->label('Fecha de salida')->displayFormat('d/m/Y'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['fecha_salida'] ?? null)
                        ? $query->conSalidaEl($data['fecha_salida'])
                        : $query)
                    ->indicateUsing(fn (array $data): ?string => filled($data['fecha_salida'] ?? null)
                        ? 'Salida: '.Carbon::parse($data['fecha_salida'])->format('d/m/Y')
                        : null),
            ])
            ->recordActions([
                ViewAction::make()->label('Ver'),
                // R-02: también se espera a que terminen los pagos con plazas retenidas.
                EditAction::make()
                    ->label('Modificar')
                    ->disabled(fn (Paquete $record) => ! $record->sePuedeModificar())
                    ->tooltip(fn (Paquete $record) => $record->sePuedeModificar() ? null : 'Tiene reservas activas o plazas retenidas mientras se completa un pago'),
                DarDeBajaPaquete::accionDarDeBaja(),
                ReactivarPaquete::accionReactivar(),
            ]);
    }
}
