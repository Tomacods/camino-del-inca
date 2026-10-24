<?php

namespace App\Filament\Resources\Paquetes\Pages;

use App\Filament\Resources\Paquetes\PaqueteResource;
use App\Models\Paquete;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

// CU-02 Modificar Paquete
class EditPaquete extends EditRecord
{
    // Como al crear: el paquete, sus servicios y sus excursiones se guardan juntos o no se guarda nada.
    protected ?bool $hasDatabaseTransactions = true;

    protected static string $resource = PaqueteResource::class;

    // R-02: un paquete con reservas activas no se modifica. Si se llega por la dirección, se avisa y se vuelve al listado.
    public function mount(int|string $record): void
    {
        parent::mount($record);

        try {
            $this->getRecord()->exigirSinReservasActivas();
        } catch (DomainException $excepcion) {
            $this->avisarQueNoSePuedeModificar($excepcion->getMessage());

            $this->redirect(static::getResource()::getUrl('index'));
        }
    }

    // Antes de guardar nada, y dentro de la transacción: el paquete vuelve a controlar las reservas, por si entró una
    // mientras se editaba, y bloquea y revisa a los guías, como en el alta (CU-01). Tiene que ser acá: Filament guarda
    // los servicios y las excursiones justo después de este paso, antes de handleRecordUpdate().
    protected function beforeSave(): void
    {
        $paquete = $this->getRecord();

        try {
            $paquete->exigirSinReservasActivas();

            Paquete::confirmarGuiasDisponibles(
                array_values($this->data['excursiones'] ?? []),
                $paquete->id_recorrido,
                $paquete->id_paquete,
            );
        } catch (DomainException $excepcion) {
            $this->avisarQueNoSePuedeModificar($excepcion->getMessage());

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->modificarPaquete($data);

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }

    private function avisarQueNoSePuedeModificar(string $motivo): void
    {
        Notification::make()
            ->title('Este paquete no se puede modificar')
            ->body($motivo)
            ->danger()
            ->send();
    }
}
