<?php

namespace App\Filament\Resources\Paquetes\Pages;

use App\Filament\Resources\Paquetes\PaqueteResource;
use App\Models\Paquete;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

// CU-01 Crear Paquete
class CreatePaquete extends CreateRecord
{
    protected static string $resource = PaqueteResource::class;

    // El modelo crea el paquete en estado «Activo». Los servicios y las excursiones los guarda después Filament, con las
    // relaciones del formulario, dentro de la misma transacción.
    protected function handleRecordCreation(array $data): Model
    {
        return Paquete::crearPaquete($data);
    }

    // Después de crearlo se vuelve al listado, donde aparece primero.
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
