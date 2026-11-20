<?php

namespace App\Filament\Resources\Paquetes\Schemas;

use App\Models\Excursion;
use App\Models\Guia;
use App\Models\Paquete;
use App\Models\Recorrido;
use App\Models\Servicio;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

// Los datos del R-01, para crear (CU-01) y modificar (CU-02). El formulario valida el formato, con los máximos de las
// columnas (decimal(10,2) en los precios y smallint en porteadores y cupo); las reglas de negocio (salidas los lunes y
// guías sin superposiciones) las deciden los modelos Excursion y Guia.
class PaqueteForm
{
    private const PRECIO_MAXIMO = 99999999.99;

    private const ENTERO_MAXIMO = 32767;

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Datos del paquete')
                ->columns(2)
                ->schema([
                    TextInput::make('nombre')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(80)
                        ->unique(ignoreRecord: true),
                    // R-02: el recorrido no se modifica, porque define la duración y las etapas de las excursiones.
                    Select::make('id_recorrido')
                        ->label('Recorrido')
                        ->relationship('recorrido', 'nombre')
                        ->required()
                        ->live()
                        ->disabledOn('edit')
                        ->helperText('Define la duración del viaje y las etapas que reporta el guía.'),
                    TextInput::make('cantidad_porteadores')
                        ->label('Cantidad de porteadores')
                        ->integer()
                        ->minValue(0)
                        ->maxValue(self::ENTERO_MAXIMO)
                        ->required(),
                ]),

            Section::make('Precios por persona')
                ->columns(3)
                ->schema([
                    TextInput::make('precio_base')
                        ->label('Precio base')
                        ->prefix('USD')
                        ->numeric()
                        ->minValue(0.01)
                        ->maxValue(self::PRECIO_MAXIMO)
                        ->required(),
                    TextInput::make('costo_noche_extra_cusco')
                        ->label('Noche extra en Cusco')
                        ->prefix('USD')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(self::PRECIO_MAXIMO)
                        ->required(),
                    TextInput::make('costo_equipo_camping')
                        ->label('Equipo de camping')
                        ->prefix('USD')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(self::PRECIO_MAXIMO)
                        ->required(),
                ]),

            Section::make('Servicios incluidos')
                ->description('Son los mismos para todas las excursiones del paquete.')
                ->schema([
                    CheckboxList::make('servicios')
                        ->hiddenLabel()
                        ->relationship('servicios', 'nombre')
                        ->getOptionLabelFromRecordUsing(fn (Servicio $servicio) => $servicio->nombre.' ('.$servicio->tipo->value.')')
                        ->columns(2)
                        ->required(),
                ]),

            Section::make('Excursiones')
                ->description('Cada una con su fecha de salida (siempre un lunes), su cupo y su guía. Al modificar el paquete, '
                    .'la fecha de las excursiones que ya existen no cambia: se puede cambiar su cupo y su guía, o agregar otras. '
                    .'En las que ya salieron tampoco cambian el cupo ni el guía.')
                ->schema([
                    Repeater::make('excursiones')
                        ->hiddenLabel()
                        ->relationship()
                        ->schema([
                            // R-02: en las excursiones que ya existen, la fecha queda fija y no se vuelve a validar.
                            DatePicker::make('fecha_salida')
                                ->label('Fecha de salida')
                                ->displayFormat('d/m/Y')
                                ->disabled(fn (?Excursion $record): bool => $record !== null)
                                ->validatedWhenNotDehydrated(false)
                                ->live()
                                ->required()
                                ->after('today')
                                ->distinct()
                                ->rules([
                                    fn (): Closure => function (string $attribute, $value, Closure $fail) {
                                        if (filled($value) && ! Excursion::esDiaDeSalida(Carbon::parse($value))) {
                                            $fail('La salida tiene que ser un lunes.');
                                        }
                                    },
                                ]),
                            // R-02: en las excursiones que ya salieron, el cupo y el guía quedan fijos, como la fecha.
                            TextInput::make('cupo')
                                ->label('Cupo')
                                ->disabled(fn (?Excursion $record): bool => $record?->yaSalio() ?? false)
                                ->validatedWhenNotDehydrated(false)
                                ->integer()
                                ->minValue(1)
                                ->maxValue(self::ENTERO_MAXIMO)
                                ->required(),
                            // R-01: sólo los guías libres para esta salida; la lista cambia con la fecha y el recorrido. Al
                            // guardar se vuelve a validar (regla del repetidor), por las filas del mismo formulario con el
                            // mismo guía y por los cambios hechos desde otra pestaña.
                            Select::make('id_guia')
                                ->label('Guía')
                                ->options(fn (Get $get, ?Excursion $record): array => self::guiasDisponibles($get, $record))
                                ->disabled(fn (Get $get, ?Excursion $record): bool => ($record?->yaSalio() ?? false) || ! self::tieneFechaYRecorrido($get))
                                ->validatedWhenNotDehydrated(false)
                                ->placeholder(fn (Get $get): ?string => self::tieneFechaYRecorrido($get) ? null : 'Elegí primero la fecha de salida')
                                ->required(),
                        ])
                        ->columns(3)
                        ->defaultItems(1)
                        ->minItems(1)
                        ->addActionLabel('Agregar excursión')
                        // R-02 no prevé quitar excursiones: al modificar sólo se agregan.
                        ->deletable(fn (string $operation): bool => $operation === 'create')
                        ->rules([
                            fn (Get $get, ?Paquete $record): Closure => function (string $attribute, $value, Closure $fail) use ($get, $record) {
                                $recorrido = Recorrido::find($get('id_recorrido'));

                                if ($recorrido === null) {
                                    return;
                                }

                                $fecha = Excursion::buscarConflictoDeGuia(
                                    array_values($value ?? []),
                                    $recorrido->duracion_dias,
                                    $record?->id_paquete,
                                );

                                if ($fecha !== null) {
                                    $fail('El guía de la salida del '.$fecha->format('d/m/Y').' ya conduce otra excursión en esas fechas.');
                                }
                            },
                        ]),
                ]),
        ]);
    }

    // Dentro de una fila del repetidor, la fecha es de la fila y el recorrido es del paquete (dos niveles más arriba).
    private static function tieneFechaYRecorrido(Get $get): bool
    {
        return filled($get('fecha_salida')) && filled($get('../../id_recorrido'));
    }

    private static function guiasDisponibles(Get $get, ?Excursion $excursion): array
    {
        // Una excursión que ya salió no cambia de guía: se muestra el que la condujo.
        if ($excursion?->yaSalio()) {
            return [$excursion->id_guia => $excursion->guia->getNombreCompleto()];
        }

        $recorrido = Recorrido::find($get('../../id_recorrido'));

        if ($recorrido === null || blank($get('fecha_salida'))) {
            return [];
        }

        return Guia::disponiblesPara(Carbon::parse($get('fecha_salida')), $recorrido->duracion_dias, $excursion?->id_excursion)
            ->mapWithKeys(fn (Guia $guia) => [$guia->id_usuario => $guia->getNombreCompleto()])
            ->all();
    }
}
