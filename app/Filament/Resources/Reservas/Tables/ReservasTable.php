<?php

namespace App\Filament\Resources\Reservas\Tables;

use App\Enums\EstadoReserva;
use App\Enums\TipoServicio;
use App\Models\Guia;
use App\Models\Paquete;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReservasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('fecha_reserva', 'desc')
            ->columns([
                TextColumn::make('numero_reserva')
                    ->label('Número')
                    ->searchable(),
                TextColumn::make('correo_electronico')
                    ->label('Correo del titular')
                    ->searchable(),
                TextColumn::make('excursion.paquete.nombre')
                    ->label('Paquete'),
                TextColumn::make('excursion.fecha_salida')
                    ->label('Salida')
                    ->date('d/m/Y'),
                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state->value),
                TextColumn::make('estado_saldo')
                    ->label('Saldo')
                    ->formatStateUsing(fn ($state) => $state->value),
                TextColumn::make('excursionistas_count')
                    ->label('Integrantes')
                    ->counts('excursionistas'),
            ])
            ->filters([
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(self::opcionesDe(EstadoReserva::cases()))
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->deEstado(EstadoReserva::from($data['value']))
                        : $query),
                SelectFilter::make('paquete')
                    ->label('Paquete')
                    ->options(fn () => Paquete::orderBy('nombre')->pluck('nombre', 'id_paquete')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->dePaquete((int) $data['value'])
                        : $query),
                SelectFilter::make('guia')
                    ->label('Guía')
                    ->options(fn () => Guia::orderBy('apellido')->get()
                        ->mapWithKeys(fn (Guia $guia) => [$guia->id_usuario => $guia->apellido.', '.$guia->nombre])
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->deGuia((int) $data['value'])
                        : $query),
                SelectFilter::make('servicio')
                    ->label('Servicio incluido')
                    ->options(self::opcionesDe(TipoServicio::cases()))
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->conServicio(TipoServicio::from($data['value']))
                        : $query),
                Filter::make('con_equipo_camping')
                    ->label('Con equipo de camping')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->conEquipoCamping()),
            ])
            ->recordActions([
                ViewAction::make()->label('Ver'),
            ]);
    }

    // Opciones de un filtro a partir de un enum: el texto es el valor del enum
    private static function opcionesDe(array $casos): array
    {
        return collect($casos)->mapWithKeys(fn ($caso) => [$caso->value => $caso->value])->all();
    }
}
