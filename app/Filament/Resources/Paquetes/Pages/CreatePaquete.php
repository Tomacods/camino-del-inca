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
    // El panel no usa transacciones. Esta página sí: el paquete, sus servicios y sus excursiones se guardan juntos, y si
    // algo falla no queda un paquete «Activo» incompleto.
    protected ?bool $hasDatabaseTransactions = true;

    protected static string $resource = PaqueteResource::class;

    // El modelo crea el paquete en estado «Activo». Los servicios y las excursiones los guarda después Filament, con las
    // relaciones del formulario, en la misma transacción.
    protected function handleRecordCreation(array $data): Model
    {
        return Paquete::crearPaquete($data);
    }

    // Ya guardadas las excursiones y todavía dentro de la transacción, el paquete confirma que sus guías sigan libres.
    // Si otro pedido ocupó a uno mientras tanto, se deshace todo.
    protected function afterCreate(): void
    {
        try {
            $this->getRecord()->confirmarGuiasDisponibles();
        } catch (DomainException $excepcion) {
            Notification::make()
                ->title('No se creó el paquete')
                ->body($excepcion->getMessage())
                ->danger()
                ->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }

    // Después de crearlo se vuelve al listado, donde aparece primero.
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
