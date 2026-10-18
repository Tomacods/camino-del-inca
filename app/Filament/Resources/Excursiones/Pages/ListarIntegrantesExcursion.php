<?php

namespace App\Filament\Resources\Excursiones\Pages;

use App\Enums\EstadoPermiso;
use App\Enums\EstadoReserva;
use App\Filament\Resources\Excursiones\Actions\IniciarRecorrido;
use App\Filament\Resources\Excursiones\Actions\ReportarEtapa;
use App\Filament\Resources\Excursiones\ExcursionResource;
use App\Models\Excursion;
use App\Models\Excursionista;
use App\Models\Reserva;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Locked;

// Listar Integrantes de la Excursión (CU-10). No tiene DSS ni DS: la página sólo coordina. La excursión decide qué
// reservas cuentan y cada integrante, qué datos se muestran.
class ListarIntegrantesExcursion extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ExcursionResource::class;

    // Paquete, fecha de salida, guía, cupo y cantidad de integrantes, como los devuelve getEncabezado().
    #[Locked]
    public array $datosExcursion = [];

    // Una entrada por reserva confirmada: su número y los datos de sus integrantes, como los devuelve getDatos().
    #[Locked]
    public array $reservas = [];

    // Si ya se reportó la etapa final (CU-12): sus reservas confirmadas pasaron a «Finalizada» y la página no las lista.
    #[Locked]
    public bool $recorridoTerminado = false;

    // Como canViewAny() de ExcursionResource: el administrador no entra ni escribiendo la dirección a mano.
    public static function canAccess(array $parameters = []): bool
    {
        return ExcursionResource::canViewAny();
    }

    // Sólo el ícono, con el texto en el tooltip: tocar la fila ya abre esta página, y así la tabla de «Mis excursiones»
    // deja más lugar para la acción de Reportar Etapa (CU-12).
    public static function accionListarIntegrantes(): Action
    {
        return Action::make('listarIntegrantes')
            ->label('Ver integrantes')
            ->iconButton()
            ->tooltip('Ver integrantes')
            ->icon(Heroicon::OutlinedUserGroup)
            ->url(fn (Excursion $record) => ExcursionResource::getUrl('integrantes', ['record' => $record]));
    }

    // Primero el rol: Filament lo controla recién después de mount(), y sin esto el administrador recibiría un 404 en vez de
    // un 403. Después, resolveRecord() busca con getEloquentQuery() de ExcursionResource, que sólo tiene las excursiones
    // del guía: la de otro guía no se encuentra (404).
    public function mount(int|string $record): void
    {
        static::authorizeResourceAccess();

        $this->record = $this->resolveRecord($record);

        $this->seleccionarExcursion($this->record->id_paquete, $this->record->fecha_salida->format('Y-m-d'));
    }

    // Busca la excursión y guarda el encabezado, las reservas confirmadas con sus integrantes y si el recorrido terminó. No
    // es pública: la excursión es la de la dirección, y desde el navegador no se puede cambiar.
    protected function seleccionarExcursion(int $idPaquete, string $fechaSalida): void
    {
        $excursion = Excursion::seleccionarExcursion($idPaquete, $fechaSalida);

        if ($excursion === null) {
            abort(404);
        }

        $this->datosExcursion = $excursion->getEncabezado();
        $this->recorridoTerminado = $excursion->esEtapaFinal();

        $this->reservas = $excursion->getReservasConfirmadas()
            ->map(fn (Reserva $reserva) => [
                'numero_reserva' => $reserva->numero_reserva,
                'integrantes' => $reserva->getExcursionistas()
                    ->map(fn (Excursionista $excursionista) => $excursionista->getDatos())
                    ->all(),
            ])
            ->all();
    }

    // Iniciar Recorrido (CU-11): el guía suele mirar los integrantes justo antes de salir. Reportar Etapa (CU-12): en cada
    // etapa el guía puede revisar quiénes van en el grupo y reportar desde la misma página.
    protected function getHeaderActions(): array
    {
        return [
            IniciarRecorrido::accionIniciarRecorrido(),
            ReportarEtapa::accionReportarEtapa(),
        ];
    }

    public function getTitle(): string|Htmlable
    {
        return 'Integrantes · '.$this->datosExcursion['paquete'].' · '.$this->datosExcursion['fecha_salida']->format('d/m/Y');
    }

    public function getBreadcrumb(): string
    {
        return 'Integrantes';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(['default' => 2, 'md' => 5])
                ->schema([
                    TextEntry::make('paquete')->label('Paquete')
                        ->state($this->datosExcursion['paquete']),
                    TextEntry::make('fecha_salida')->label('Salida')
                        ->state($this->datosExcursion['fecha_salida'])->date('d/m/Y'),
                    TextEntry::make('guia')->label('Guía')
                        ->state($this->datosExcursion['guia']),
                    TextEntry::make('cupo')->label('Cupo')
                        ->state($this->datosExcursion['cupo']),
                    TextEntry::make('integrantes')->label('Integrantes confirmados')
                        ->state($this->datosExcursion['integrantes']),
                ]),

            // A1. Si el recorrido terminó, no es que todavía no haya integrantes: sus reservas ya no están «Confirmada».
            Callout::make($this->recorridoTerminado
                ? 'Esta excursión terminó: sus reservas pasaron a «'.EstadoReserva::Finalizada->value.'».'
                : 'Esta excursión todavía no tiene integrantes confirmados')
                ->info()
                ->visible($this->reservas === []),

            ...array_map(fn (array $reserva, int $posicion) => $this->seccionReserva($reserva, $posicion), $this->reservas, array_keys($this->reservas)),
        ]);
    }

    // Los integrantes de una reserva, en una tabla. Cada tabla de la página necesita un nombre distinto: se usa la
    // posición de la reserva.
    private function seccionReserva(array $reserva, int $posicion): Section
    {
        return Section::make('Reserva '.$reserva['numero_reserva'])
            ->schema([
                RepeatableEntry::make('reserva_'.$posicion)
                    ->label('Integrantes de la reserva '.$reserva['numero_reserva'])
                    ->hiddenLabel()
                    ->state($reserva['integrantes'])
                    ->table([
                        TableColumn::make('Nombre'),
                        TableColumn::make('Apellido'),
                        TableColumn::make('Documento o pasaporte'),
                        TableColumn::make('Equipo de camping'),
                        TableColumn::make('Permiso'),
                    ])
                    ->schema([
                        TextEntry::make('nombre')->label('Nombre'),
                        TextEntry::make('apellido')->label('Apellido'),
                        TextEntry::make('documento_pasaporte')->label('Documento o pasaporte'),
                        TextEntry::make('equipo_camping')->label('Equipo de camping')
                            ->formatStateUsing(fn (bool $state) => $state ? 'Sí' : 'No'),
                        TextEntry::make('estado_permiso')->label('Permiso')
                            ->formatStateUsing(fn (EstadoPermiso $state) => $state->value),
                    ]),
            ]);
    }
}
