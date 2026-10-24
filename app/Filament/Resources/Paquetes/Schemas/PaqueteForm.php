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
// guías sin superposiciones) las decide el modelo Excursion.
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
                    .'la fecha de las excursiones que ya existen no cambia: se puede cambiar su cupo y su guía, o agregar otras.')
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
                            TextInput::make('cupo')
                                ->label('Cupo')
                                ->integer()
                                ->minValue(1)
                                ->maxValue(self::ENTERO_MAXIMO)
                                ->required(),
                            Select::make('id_guia')
                                ->label('Guía')
                                ->options(fn () => Guia::orderBy('apellido')->get()
                                    ->mapWithKeys(fn (Guia $guia) => [$guia->id_usuario => $guia->getNombreCompleto()])
                                    ->all())
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
}
