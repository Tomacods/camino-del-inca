<?php

namespace App\Filament\Resources\Valoraciones\Tables;

use App\Enums\CategoriaValoracion;
use App\Enums\TipoServicio;
use App\Models\Guia;
use App\Models\Paquete;
use App\Models\Servicio;
use App\Models\Valoracion;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

// CU-08 (R-08): todas las valoraciones, con búsqueda y filtros; sin filtros se ven todas. Los filtros siguen los del
// listado de reservas (CU-07), y se suma uno por puntaje, para encontrar, por ejemplo, las que le dieron 1 estrella al guía.
class ValoracionesTable
{
    private const EXPERIENCIA_GENERAL = 'experiencia_general';

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['reserva.excursion.paquete', 'reserva.excursion.guia']))
            ->defaultSort('fecha', 'desc')
            ->columns([
                TextColumn::make('fecha')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('reserva.numero_reserva')
                    ->label('Reserva')
                    ->searchable(),
                TextColumn::make('reserva.excursion.paquete.nombre')
                    ->label('Paquete')
                    ->searchable(),
                TextColumn::make('reserva.excursion.fecha_salida')
                    ->label('Salida')
                    ->date('d/m/Y'),
                TextColumn::make('guia')
                    ->label('Guía')
                    ->state(fn (Valoracion $record) => $record->reserva->excursion->guia->getNombreCompleto()),
                TextColumn::make('puntaje_experiencia_general')
                    ->label('Experiencia general')
                    ->formatStateUsing(fn ($state) => $state.' de 5')
                    ->sortable(),
                TextColumn::make('comentario')
                    ->label('Comentario')
                    ->limit(60)
                    ->placeholder('—')
                    ->searchable(),
            ])
            ->filters([
                SelectFilter::make('paquete')
                    ->label('Paquete')
                    ->options(fn () => Paquete::orderBy('nombre')->pluck('nombre', 'id_paquete')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->dePaquete((int) $data['value'])
                        : $query),
                ...self::filtrosDeServicio(),
                SelectFilter::make('guia')
                    ->label('Guía')
                    ->options(fn () => Guia::orderBy('apellido')->get()
                        ->mapWithKeys(fn (Guia $guia) => [$guia->id_usuario => $guia->apellido.', '.$guia->nombre])
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->deGuia((int) $data['value'])
                        : $query),
                Filter::make('con_porteadores')
                    ->label('Con porteadores')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->conPorteadores()),
                Filter::make('con_equipo_camping')
                    ->label('Con equipo de camping')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->conEquipoCamping()),
                Filter::make('puntaje')
                    ->schema([
                        Select::make('categoria')
                            ->label('Puntaje de')
                            ->options([
                                self::EXPERIENCIA_GENERAL => 'Experiencia general',
                                ...collect(CategoriaValoracion::cases())
                                    ->mapWithKeys(fn (CategoriaValoracion $categoria) => [$categoria->name => $categoria->value])
                                    ->all(),
                            ]),
                        Select::make('puntaje')
                            ->label('Estrellas')
                            ->options([1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5']),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['categoria'] ?? null) && filled($data['puntaje'] ?? null)
                        ? $query->conPuntaje(self::categoria($data['categoria']), (int) $data['puntaje'])
                        : $query),
            ])
            ->recordActions([
                ViewAction::make()->label('Ver'),
            ]);
    }

    // La opción del filtro de puntaje: la experiencia general (null) o el nombre de un caso del enum.
    private static function categoria(string $opcion): ?CategoriaValoracion
    {
        if ($opcion === self::EXPERIENCIA_GENERAL) {
            return null;
        }

        return collect(CategoriaValoracion::cases())
            ->first(fn (CategoriaValoracion $categoria) => $categoria->name === $opcion);
    }

    // Un filtro por tipo de servicio, como en el listado de reservas: elige un servicio concreto de ese tipo.
    private static function filtrosDeServicio(): array
    {
        $tipos = [
            TipoServicio::Hotel,
            TipoServicio::CampingDeEtapa,
            TipoServicio::TransporteEnBus,
            TipoServicio::TransporteFerroviario,
        ];

        $filtros = [];

        foreach ($tipos as $tipo) {
            $filtros[] = SelectFilter::make('servicio_'.Str::snake($tipo->name))
                ->label($tipo->value)
                ->options(fn () => Servicio::where('tipo', $tipo->value)->orderBy('nombre')->pluck('nombre', 'id_servicio')->all())
                ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                    ? $query->conServicio((int) $data['value'])
                    : $query);
        }

        return $filtros;
    }
}
