<?php

namespace App\Filament\Resources\Paquetes\Pages;

use App\Filament\Resources\Paquetes\PaqueteResource;
use App\Models\Paquete;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

// CU-01 Crear Paquete
class CreatePaquete extends CreateRecord
{
    // Paquete, servicios y excursiones se guardan juntos; los bloqueos de los guías duran hasta el final.
    protected ?bool $hasDatabaseTransactions = true;

    protected static string $resource = PaqueteResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            Paquete::confirmarGuiasDisponibles(array_values($this->data['excursiones']), (int) $data['id_recorrido']);
        } catch (DomainException $excepcion) {
            Notification::make()
                ->title('No se creó el paquete')
                ->body($excepcion->getMessage())
                ->danger()
                ->send();

            // Todavía no se escribió ningún registro: el formulario conserva sus datos y admite otro intento.
            throw (new Halt)->rollBackDatabaseTransaction();
        }

        return Paquete::crearPaquete($data);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
