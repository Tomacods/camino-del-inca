<?php

namespace App\Filament\Resources\Reservas\Pages;

use App\Enums\EstadoPermiso;
use App\Enums\EstadoReserva;
use App\Enums\TipoNotificacion;
use App\Filament\Resources\Reservas\ReservaResource;
use App\Models\Reserva;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

// Validar Permisos de Reserva (CU-04). Es el controlador del DS-04: valida el formato de lo que marca el administrador y
// coordina a la reserva, que tiene las reglas. A diferencia del DS-04, lo que se marca queda en la página y se guarda
// todo junto al confirmar: así, si el administrador cancela (A2), no queda ningún integrante validado.
class ValidarPermisosReserva extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ReservaResource::class;

    // Si no es null, la reserva no admite la validación (A3 o plazo vencido): se muestra este aviso y no el formulario.
    #[Locked]
    public ?string $aviso = null;

    // Nombre, apellido y documento de cada integrante, como los devuelve iniciarValidacionPermisos().
    #[Locked]
    public array $integrantes = [];

    // El permiso que el administrador registró para cada integrante, por documento: el valor del EstadoPermiso. Sólo lo
    // cambia procesarRegistroPermiso(), así que llega validado; a la base va recién al confirmar.
    #[Locked]
    public array $permisos = [];

    // Lo que muestran los botones de cada integrante. Cada cambio pasa por procesarRegistroPermiso().
    public array $marcas = [];

    // Datos del encabezado que no cambian mientras la página está abierta. Se leen una vez al abrirla: Livewire vuelve a
    // traer la reserva en cada clic, pero no sus relaciones, y así no se consultan la excursión y el paquete cada vez.
    #[Locked]
    public string $nombrePaquete = '';

    #[Locked]
    public string $fechaSalida = '';

    // Como canView() de ReservaResource: un guía no entra ni escribiendo la dirección a mano.
    public static function canAccess(array $parameters = []): bool
    {
        return ReservaResource::canViewAny();
    }

    // La misma acción en el listado y en el detalle. Sólo se ofrece con la reserva «Pendiente»; si el plazo venció, la
    // página lo avisa.
    public static function accionValidarPermisos(): Action
    {
        return Action::make('validarPermisos')
            ->label('Validar permisos')
            ->icon(Heroicon::OutlinedIdentification)
            ->url(fn (Reserva $record) => ReservaResource::getUrl('permisos', ['record' => $record]))
            ->visible(fn (Reserva $record) => $record->estado === EstadoReserva::Pendiente);
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $excursion = $this->record->excursion;
        $this->nombrePaquete = $excursion->paquete->nombre;
        $this->fechaSalida = $excursion->fecha_salida->format('Y-m-d');

        $this->seleccionarReservaAValidar($this->record->numero_reserva);

        $this->form->fill();
    }

    // Pasos 1 y 2 y A3. No es pública: la reserva es la de la dirección, y desde el navegador no se puede cambiar.
    protected function seleccionarReservaAValidar(string $numeroReserva): void
    {
        $reserva = Reserva::buscarReserva($numeroReserva);

        try {
            $this->integrantes = $reserva->iniciarValidacionPermisos();
        } catch (DomainException $excepcion) {
            $this->aviso = $excepcion->getMessage();
        }
    }

    // Paso 3, por cada integrante. Sólo valida el formato y lo anota en la página: no escribe en la base (A2).
    public function procesarRegistroPermiso(string $documentoPasaporte, ?string $estadoPermiso): void
    {
        // El campo de cada integrante es su posición en $integrantes: el error se muestra debajo de sus botones.
        $posicion = array_search($documentoPasaporte, array_column($this->integrantes, 'documento_pasaporte'), true);

        if ($posicion === false) {
            throw ValidationException::withMessages([
                'marcas' => 'Ese documento no es de un integrante de esta reserva.',
            ]);
        }

        $estado = EstadoPermiso::tryFrom((string) $estadoPermiso);

        if (! in_array($estado, [EstadoPermiso::Obtenido, EstadoPermiso::NoObtenido], true)) {
            throw ValidationException::withMessages([
                'marcas.'.$posicion => 'Elegí «Obtenido» o «No obtenido».',
            ]);
        }

        $this->permisos[$documentoPasaporte] = $estado->value;
    }

    // Pasos 4 a 6 y A1. Todo en una transacción, con la fila de la reserva bloqueada: mientras el administrador marcaba,
    // la reserva pudo cancelarse (CU-21 o CU-26) o vencer el plazo, así que se vuelve a validar antes de registrar.
    public function confirmarValidacionPermisos(): void
    {
        if ($this->contarSinRegistrar() > 0) {
            Notification::make()
                ->title('Falta registrar el permiso de '.$this->contarSinRegistrar().' integrante(s).')
                ->danger()
                ->send();

            return;
        }

        try {
            $reserva = DB::transaction(function () {
                $reserva = Reserva::lockForUpdate()->findOrFail($this->getRecord()->getKey());
                $reserva->iniciarValidacionPermisos();

                foreach ($this->permisos as $documentoPasaporte => $estadoPermiso) {
                    $reserva->registrarPermiso((string) $documentoPasaporte, EstadoPermiso::from($estadoPermiso));
                }

                $reserva->ejecutarValidacionPermisos();

                return $reserva;
            });
        } catch (DomainException $excepcion) {
            // No se guardó nada. Si la reserva ya tiene los permisos validados, la confirmación llegó dos veces (doble clic,
            // otra pestaña u otro administrador): no es un error, se informa cómo quedó y se vuelve al detalle.
            $reservaActual = $this->getRecord()->fresh();

            if ($reservaActual?->tienePermisosValidados()) {
                Notification::make()
                    ->title('Los permisos de la reserva '.$reservaActual->numero_reserva.' ya se validaron: está en «'.$reservaActual->estado->value.'».')
                    ->warning()
                    ->send();

                $this->redirect(ReservaResource::getUrl('view', ['record' => $reservaActual]));

                return;
            }

            // La reserva ya no admite la validación: se muestra el aviso en lugar del formulario.
            $this->aviso = $excepcion->getMessage();

            Notification::make()
                ->title('No se validaron los permisos')
                ->body($excepcion->getMessage())
                ->danger()
                ->send();

            return;
        }

        // Paso 6 y A1: Notificar Cliente (CU-13), con el tipo según el estado en que quedó la reserva. La validación ya se
        // guardó: si el correo no sale, se avisa y la reserva queda como está (A8 de CU-13).
        $tipoNotificacion = $reserva->estado === EstadoReserva::Confirmada
            ? TipoNotificacion::PermisosObtenidos
            : TipoNotificacion::PermisosRechazados;
        $correoEnviado = Reserva::notificarCliente($tipoNotificacion, $reserva->numero_reserva);

        Notification::make()
            ->title('Reserva '.$reserva->numero_reserva.' en «'.$reserva->estado->value.'».')
            ->body($correoEnviado
                ? 'Correo «'.$tipoNotificacion->value.'» enviado a '.$reserva->correo_electronico.'.'
                : 'No se pudo enviar el correo «'.$tipoNotificacion->value.'» a '.$reserva->correo_electronico.': quedó anotado en el registro.')
            ->status($correoEnviado ? 'success' : 'warning')
            ->send();

        $this->redirect(ReservaResource::getUrl('view', ['record' => $reserva]));
    }

    public function getTitle(): string|Htmlable
    {
        return 'Validar permisos · Reserva '.$this->getRecord()->numero_reserva;
    }

    public function getBreadcrumb(): string
    {
        return 'Validar permisos';
    }

    public function content(Schema $schema): Schema
    {
        $reserva = $this->getRecord();

        return $schema->components([
            Section::make()
                ->columns(4)
                ->schema([
                    TextEntry::make('estado')->label('Estado')->badge()
                        ->state($reserva->estado->value),
                    TextEntry::make('paquete')->label('Paquete')
                        ->state($this->nombrePaquete),
                    TextEntry::make('fecha_salida')->label('Salida')
                        ->state($this->fechaSalida)->date('d/m/Y'),
                    TextEntry::make('fecha_limite_confirmacion')->label('Límite de confirmación')
                        ->state($reserva->fecha_limite_confirmacion)->dateTime('d/m/Y H:i'),
                ]),

            Callout::make('Esta reserva no admite validación de permisos')
                ->description(fn () => $this->aviso)
                ->warning()
                ->visible(fn () => $this->aviso !== null),

            Section::make('Integrantes')
                ->visible(fn () => $this->aviso === null)
                ->schema([
                    EmbeddedSchema::make('form'),
                    Text::make(fn () => $this->adelantarResultado()),
                ]),

            Actions::make([
                Action::make('confirmar')
                    ->label('Confirmar validación')
                    ->visible(fn () => $this->aviso === null)
                    ->requiresConfirmation()
                    ->modalHeading('Confirmar la validación de permisos')
                    ->modalDescription(fn () => $this->adelantarResultado())
                    ->modalSubmitActionLabel('Confirmar')
                    ->action(fn () => $this->confirmarValidacionPermisos()),
                // A2: vuelve al detalle sin guardar nada.
                Action::make('cancelar')
                    ->label(fn () => $this->aviso === null ? 'Cancelar' : 'Volver al detalle')
                    ->color('gray')
                    ->url(ReservaResource::getUrl('view', ['record' => $reserva])),
            ])->key('acciones'),
        ]);
    }

    // Una fila por integrante, con los botones «Obtenido» y «No obtenido». Cada campo se llama con la posición del
    // integrante (marcas.0, marcas.1, ...) y no con su documento: el nombre arma la ruta del dato, y un documento con
    // un punto se tomaría como un nivel más.
    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('marcas')
            ->components(array_map(fn (array $integrante, int $posicion) => Grid::make(['default' => 1, 'md' => 3])->schema([
                Text::make($integrante['nombre'].' '.$integrante['apellido'])->weight(FontWeight::SemiBold),
                Text::make('Documento '.$integrante['documento_pasaporte']),
                ToggleButtons::make((string) $posicion)
                    ->label('Permiso de '.$integrante['nombre'].' '.$integrante['apellido'])
                    ->hiddenLabel()
                    ->inline()
                    ->options([
                        EstadoPermiso::Obtenido->value => 'Obtenido',
                        EstadoPermiso::NoObtenido->value => 'No obtenido',
                    ])
                    ->colors([
                        EstadoPermiso::Obtenido->value => 'success',
                        EstadoPermiso::NoObtenido->value => 'danger',
                    ])
                    ->live()
                    ->afterStateUpdated(fn (?string $state) => $this->procesarRegistroPermiso($integrante['documento_pasaporte'], $state)),
            ]), $this->integrantes, array_keys($this->integrantes)));
    }

    private function contarSinRegistrar(): int
    {
        return count($this->integrantes) - count($this->permisos);
    }

    // La línea que adelanta lo que va a pasar al confirmar. La regla la decide la reserva.
    private function adelantarResultado(): string
    {
        if ($this->contarSinRegistrar() > 0) {
            return 'Resultado: falta registrar el permiso de '.$this->contarSinRegistrar().' integrante(s).';
        }

        $estadoFinal = Reserva::anticiparResultadoValidacion(array_map(fn (string $valor) => EstadoPermiso::from($valor), $this->permisos));
        $sinPermiso = count(array_keys($this->permisos, EstadoPermiso::NoObtenido->value, true));

        if ($estadoFinal === EstadoReserva::Confirmada) {
            return 'Resultado: todos los integrantes obtuvieron el permiso. Al confirmar, la reserva pasa a «'.$estadoFinal->value.'».';
        }

        return 'Resultado: '.$sinPermiso.' integrante(s) sin permiso. Al confirmar, la reserva completa pasa a «'.$estadoFinal->value.'».';
    }
}
