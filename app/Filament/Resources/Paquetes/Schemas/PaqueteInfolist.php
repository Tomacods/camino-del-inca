<?php

namespace App\Filament\Resources\Paquetes\Schemas;

use App\Enums\EstadoPaquete;
use App\Enums\TipoServicio;
use App\Models\Paquete;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PaqueteInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Paquete')->columns(2)->schema([
                TextEntry::make('nombre')->label('Nombre'),
                TextEntry::make('estado')->label('Estado')->badge()
                    ->formatStateUsing(fn (EstadoPaquete $state) => $state->value),
                TextEntry::make('recorrido.nombre')->label('Recorrido'),
                TextEntry::make('recorrido.duracion_dias')->label('Duración (días)'),
                TextEntry::make('cantidad_etapas')->label('Etapas')
                    ->state(fn (Paquete $record) => $record->recorrido->contarEtapas()),
                TextEntry::make('cantidad_porteadores')->label('Porteadores'),
                TextEntry::make('fecha_creacion')->label('Creación')->date('d/m/Y'),
                TextEntry::make('promedio_valoraciones')->label('Valoración promedio')
                    ->state(fn (Paquete $record) => $record->calcularPromedioValoraciones())
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 1, ',', '.').' / 5')
                    ->placeholder('Todavía no tiene valoraciones.'),
            ]),
            Section::make('Precios')->columns(3)->schema([
                TextEntry::make('precio_base')->label('Precio base, por persona')
                    ->formatStateUsing(fn ($state) => self::dolares($state)),
                TextEntry::make('costo_noche_extra_cusco')->label('Noche extra en Cusco, por persona')
                    ->formatStateUsing(fn ($state) => self::dolares($state)),
                TextEntry::make('costo_equipo_camping')->label('Equipo de camping, por equipo')
                    ->formatStateUsing(fn ($state) => self::dolares($state)),
            ]),
            Section::make('Servicios incluidos')->schema([
                RepeatableEntry::make('servicios')->hiddenLabel()->columns(2)->schema([
                    TextEntry::make('tipo')->label('Tipo')
                        ->formatStateUsing(fn (TipoServicio $state) => $state->value),
                    TextEntry::make('nombre')->label('Nombre'),
                ])->placeholder('No tiene servicios incluidos.'),
            ]),
            Section::make('Excursiones')
                ->description('Todas las salidas, incluidas las pasadas, completas y con menos de tres meses de anticipación.')
                ->schema([
                    RepeatableEntry::make('salidas')->hiddenLabel()->columns(3)
                        ->state(fn (Paquete $record) => $record->getExcursionesParaConsulta())
                        ->schema([
                            TextEntry::make('fecha_salida')->label('Salida')->date('d/m/Y'),
                            TextEntry::make('cupo_disponible')->label('Cupo disponible'),
                            TextEntry::make('guia')->label('Guía'),
                        ])->placeholder('No tiene excursiones.'),
                ]),
        ]);
    }

    private static function dolares(mixed $monto): string
    {
        return 'USD '.number_format((float) $monto, 2, ',', '.');
    }
}
