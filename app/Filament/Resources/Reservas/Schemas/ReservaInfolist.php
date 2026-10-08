<?php

namespace App\Filament\Resources\Reservas\Schemas;

use App\Models\Reserva;
use BackedEnum;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ReservaInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Reserva')
                ->columns(3)
                ->schema([
                    TextEntry::make('numero_reserva')->label('Número'),
                    TextEntry::make('estado')->label('Estado')->badge()
                        ->formatStateUsing(fn ($state) => self::texto($state)),
                    TextEntry::make('estado_saldo')->label('Estado del saldo')
                        ->formatStateUsing(fn ($state) => self::texto($state)),
                    TextEntry::make('correo_electronico')->label('Correo del titular'),
                    TextEntry::make('excursion.paquete.nombre')->label('Paquete'),
                    TextEntry::make('excursion.fecha_salida')->label('Salida')->date('d/m/Y'),
                    TextEntry::make('noches_extra_antes')->label('Noches extra antes'),
                    TextEntry::make('noches_extra_despues')->label('Noches extra después'),
                    TextEntry::make('fecha_limite_confirmacion')->label('Límite de confirmación')
                        ->dateTime('d/m/Y H:i'),
                    TextEntry::make('fecha_limite_saldo')->label('Límite de pago del saldo')
                        ->dateTime('d/m/Y H:i')->placeholder('—'),
                ]),

            Section::make('Montos')
                ->columns(2)
                ->schema([
                    TextEntry::make('monto_total')->label('Monto total')
                        ->state(fn (Reserva $record) => $record->obtenerMontoTotal())
                        ->formatStateUsing(fn ($state) => self::dolares($state)),
                    TextEntry::make('saldo_pendiente')->label('Saldo pendiente')
                        ->state(fn (Reserva $record) => $record->calcularSaldoPendiente())
                        ->formatStateUsing(fn ($state) => self::dolares($state)),
                ]),

            Section::make('Integrantes')
                ->schema([
                    RepeatableEntry::make('excursionistas')
                        ->hiddenLabel()
                        ->columns(5)
                        ->schema([
                            TextEntry::make('nombre')->label('Nombre'),
                            TextEntry::make('apellido')->label('Apellido'),
                            TextEntry::make('documento_pasaporte')->label('Documento'),
                            TextEntry::make('equipo_camping')->label('Equipo de camping')
                                ->formatStateUsing(fn ($state) => $state ? 'Sí' : 'No'),
                            TextEntry::make('estado_permiso')->label('Permiso')
                                ->formatStateUsing(fn ($state) => self::texto($state)),
                        ]),
                ]),

            Section::make('Pagos')
                ->schema([
                    RepeatableEntry::make('pagos')
                        ->hiddenLabel()
                        ->columns(4)
                        ->schema([
                            TextEntry::make('fecha')->label('Fecha')->date('d/m/Y'),
                            TextEntry::make('tipo_pago')->label('Tipo')
                                ->formatStateUsing(fn ($state) => self::texto($state)),
                            TextEntry::make('monto')->label('Monto')
                                ->formatStateUsing(fn ($state) => self::dolares($state)),
                            TextEntry::make('medio_pago')->label('Medio'),
                        ]),
                ]),

            Section::make('Devolución')
                ->columns(3)
                ->visible(fn (Reserva $record) => $record->devolucion !== null)
                ->schema([
                    TextEntry::make('devolucion.fecha')->label('Fecha')->date('d/m/Y'),
                    TextEntry::make('devolucion.monto')->label('Monto')
                        ->formatStateUsing(fn ($state) => self::dolares($state)),
                    TextEntry::make('devolucion.motivo')->label('Motivo')
                        ->formatStateUsing(fn ($state) => self::texto($state)),
                ]),
        ]);
    }

    // Los enums se muestran con su valor; si la columna no estuviera convertida, se muestra tal cual
    private static function texto(mixed $estado): string
    {
        return $estado instanceof BackedEnum ? (string) $estado->value : (string) $estado;
    }

    private static function dolares(mixed $monto): string
    {
        return 'USD '.number_format((float) $monto, 0, ',', '.');
    }
}
