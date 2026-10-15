<?php

namespace App\Filament\Resources\Excursiones\Tables;

use App\Filament\Resources\Excursiones\Actions\IniciarRecorrido;
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
            // La columna de integrantes los cuenta por reserva, y la acción de Iniciar Recorrido (CU-11) mira la duración del
            // recorrido: se traen con la excursión para no consultar por cada fila.
            // Filament pasa la consulta por el nombre del parámetro: tiene que llamarse $query.
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['paquete.recorrido', 'reservasConfirmadas.excursionistas']))
            // Primero las salidas de hoy en adelante, de la más cercana a la más lejana; después las pasadas, de la más
            // reciente a la más vieja. El primer CASE separa los dos grupos; los otros dos ordenan cada uno.
            ->defaultSort(fn (Builder $query) => $query
                ->orderByRaw('CASE WHEN fecha_salida >= ? THEN 0 ELSE 1 END', [today()->toDateString()])
                ->orderByRaw('CASE WHEN fecha_salida >= ? THEN fecha_salida END ASC', [today()->toDateString()])
                ->orderByRaw('CASE WHEN fecha_salida < ? THEN fecha_salida END DESC', [today()->toDateString()]))
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
                TextColumn::make('fecha_hora_inicio_recorrido')
                    ->label('Inicio del recorrido')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
            ])
            ->recordUrl(fn (Excursion $record) => ExcursionResource::getUrl('integrantes', ['record' => $record]))
            // Acá se va a sumar la acción de Reportar Etapa (CU-12).
            ->recordActions([
                ListarIntegrantesExcursion::accionListarIntegrantes(),
                IniciarRecorrido::accionIniciarRecorrido(),
            ])
            // A2: el guía todavía no tiene excursiones a su cargo.
            ->emptyStateHeading('Todavía no tenés excursiones asignadas')
            ->emptyStateDescription('Cuando tengas una salida a tu cargo, va a aparecer en esta lista.');
    }
}
