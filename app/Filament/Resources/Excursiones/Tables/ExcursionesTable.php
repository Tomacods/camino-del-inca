<?php

namespace App\Filament\Resources\Excursiones\Tables;

use App\Filament\Resources\Excursiones\ExcursionResource;
use App\Filament\Resources\Excursiones\Pages\ListarIntegrantesExcursion;
use App\Models\Excursion;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ExcursionesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // La columna de integrantes los cuenta por reserva: se traen con la excursión para no consultar por cada fila.
            // Filament pasa la consulta por el nombre del parámetro: tiene que llamarse $query.
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['paquete', 'reservasConfirmadas.excursionistas']))
            ->defaultSort('fecha_salida')
            ->columns([
                TextColumn::make('paquete.nombre')
                    ->label('Paquete'),
                TextColumn::make('fecha_salida')
                    ->label('Salida')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('cupo')
                    ->label('Cupo'),
                TextColumn::make('integrantes')
                    ->label('Integrantes confirmados')
                    ->state(fn (Excursion $record) => $record->contarIntegrantesConfirmados()),
            ])
            ->recordUrl(fn (Excursion $record) => ExcursionResource::getUrl('integrantes', ['record' => $record]))
            // Acá se van a sumar las acciones de Iniciar Recorrido (CU-11) y Reportar Etapa (CU-12).
            ->recordActions([
                ListarIntegrantesExcursion::accionListarIntegrantes(),
            ])
            // A2: el guía todavía no tiene excursiones a su cargo.
            ->emptyStateHeading('Todavía no tenés excursiones asignadas')
            ->emptyStateDescription('Cuando tengas una salida a tu cargo, va a aparecer en esta lista.');
    }
}
