<?php

namespace App\Filament\Resources\Valoraciones\Schemas;

use App\Enums\CategoriaValoracion;
use App\Models\DetalleValoracion;
use App\Models\Valoracion;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

// CU-09 (R-09): los puntajes por categoría, el comentario del cliente y los datos de la reserva y del paquete.
class ValoracionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Valoración')
                ->columns(2)
                ->schema([
                    TextEntry::make('fecha')->label('Fecha')->dateTime('d/m/Y H:i'),
                    TextEntry::make('puntaje_experiencia_general')->label('Experiencia general')
                        ->formatStateUsing(fn ($state) => self::estrellas($state)),
                    TextEntry::make('comentario')->label('Comentario del cliente')
                        ->placeholder('Sin comentario')
                        ->columnSpanFull(),
                ]),

            Section::make('Puntajes por categoría')
                ->schema([
                    // En el orden de las categorías del R-23, no en el que quedaron guardados.
                    TextEntry::make('puntajes')
                        ->hiddenLabel()
                        ->state(fn (Valoracion $record) => $record->detalles
                            ->sortBy(fn (DetalleValoracion $detalle) => array_search($detalle->categoria, CategoriaValoracion::cases(), true))
                            ->map(fn (DetalleValoracion $detalle) => $detalle->categoria->value.': '.self::estrellas($detalle->puntaje))
                            ->values()
                            ->all())
                        ->listWithLineBreaks(),
                ]),

            Section::make('Reserva y paquete')
                ->columns(3)
                ->schema([
                    TextEntry::make('reserva.numero_reserva')->label('Reserva'),
                    TextEntry::make('reserva.correo_electronico')->label('Correo del titular'),
                    TextEntry::make('integrantes')->label('Integrantes')
                        ->state(fn (Valoracion $record) => $record->reserva->excursionistas()->count()),
                    TextEntry::make('reserva.excursion.paquete.nombre')->label('Paquete'),
                    TextEntry::make('reserva.excursion.paquete.recorrido.nombre')->label('Recorrido'),
                    TextEntry::make('reserva.excursion.fecha_salida')->label('Salida')->date('d/m/Y'),
                    TextEntry::make('guia')->label('Guía')
                        ->state(fn (Valoracion $record) => $record->reserva->excursion->guia->getNombreCompleto()),
                ]),
        ]);
    }

    // «★★★★☆ 4 de 5»: las estrellas para leerlo de un vistazo y el número para el lector de pantalla.
    private static function estrellas(mixed $puntaje): string
    {
        $puntaje = (int) $puntaje;

        return str_repeat('★', $puntaje).str_repeat('☆', 5 - $puntaje).' '.$puntaje.' de 5';
    }
}
