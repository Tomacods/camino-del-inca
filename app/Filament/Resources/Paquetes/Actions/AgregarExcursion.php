<?php

namespace App\Filament\Resources\Paquetes\Actions;

use App\Enums\EstadoPaquete;
use App\Models\Excursion;
use App\Models\Guia;
use App\Models\Paquete;
use Closure;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

// CU-27 Agregar Excursión (R-27): una salida nueva para un paquete «Activo», tenga o no reservas. Es aparte de CU-02,
// que sigue bloqueado con reservas activas. El formulario valida el formato y, para avisar antes, las mismas reglas de
// la fecha que el modelo; Paquete::agregarExcursion() las vuelve a controlar con el paquete y los guías bloqueados.
class AgregarExcursion
{
    private const ENTERO_MAXIMO = 32767;

    public static function accionAgregarExcursion(): Action
    {
        return Action::make('agregarExcursion')
            ->label('Agregar excursión')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->color('primary')
            ->visible(fn (Paquete $record) => $record->estado === EstadoPaquete::Activo)
            ->modalHeading(fn (Paquete $record) => 'Agregar una excursión a «'.$record->nombre.'»')
            ->modalDescription('La salida nueva se suma a las que ya tiene el paquete. No cambian los precios, los servicios '
                .'ni las otras excursiones, así que se puede agregar aunque el paquete tenga reservas.')
            ->modalSubmitActionLabel('Agregar')
            ->schema(fn (Paquete $record) => [
                DatePicker::make('fecha_salida')
                    ->label('Fecha de salida')
                    ->displayFormat('d/m/Y')
                    ->live()
                    ->required()
                    ->after('today')
                    ->validationMessages(['after' => 'La fecha de salida tiene que ser posterior a hoy.'])
                    ->rules([
                        fn (): Closure => function (string $attribute, $value, Closure $fail) use ($record) {
                            if (blank($value)) {
                                return;
                            }

                            if (! Excursion::esDiaDeSalida(Carbon::parse($value))) {
                                $fail('La salida tiene que ser un lunes.');
                            } elseif (Excursion::buscarPorFechaSalida($record->id_paquete, $value) !== null) {
                                $fail('El paquete ya tiene una salida en esa fecha.');
                            }
                        },
                    ]),
                TextInput::make('cupo')
                    ->label('Cupo')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(self::ENTERO_MAXIMO)
                    ->validationMessages(['min' => 'El cupo tiene que ser mayor que cero.'])
                    ->required(),
                // R-01: sólo los guías libres durante el recorrido de esta salida, como en el formulario del paquete.
                Select::make('id_guia')
                    ->label('Guía')
                    ->options(fn (Get $get): array => self::guiasDisponibles($record, $get('fecha_salida')))
                    ->disabled(fn (Get $get): bool => blank($get('fecha_salida')))
                    ->placeholder(fn (Get $get): ?string => blank($get('fecha_salida')) ? 'Elegí primero la fecha de salida' : null)
                    ->required(),
            ])
            ->action(fn (Paquete $record, array $data, Action $action) => self::agregar($record, $data, $action));
    }

    private static function guiasDisponibles(Paquete $paquete, ?string $fechaSalida): array
    {
        if (blank($fechaSalida)) {
            return [];
        }

        return Guia::disponiblesPara(Carbon::parse($fechaSalida), $paquete->recorrido->duracion_dias)
            ->mapWithKeys(fn (Guia $guia) => [$guia->id_usuario => $guia->getNombreCompleto()])
            ->all();
    }

    // Si el modelo la rechaza, se avisa el motivo y el formulario queda abierto, para corregir la fecha o el guía.
    private static function agregar(Paquete $paquete, array $datos, Action $action): void
    {
        try {
            $excursion = $paquete->agregarExcursion($datos);
        } catch (DomainException $excepcion) {
            Notification::make()
                ->title('No se agregó la excursión')
                ->body($excepcion->getMessage())
                ->danger()
                ->send();

            $action->halt();
        }

        Notification::make()
            ->title('Excursión agregada')
            ->body('«'.$paquete->nombre.'» tiene una salida nueva el '.$excursion->fecha_salida->format('d/m/Y').'.')
            ->success()
            ->send();
    }
}
