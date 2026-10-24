<?php

namespace App\Filament\Resources\Paquetes\Actions;

use App\Enums\EstadoPaquete;
use App\Models\Paquete;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

// Reactivar un paquete dado de baja (CU-02, R-02). La acción sólo coordina; el paquete revisa que sus guías sigan libres.
class ReactivarPaquete
{
    public static function accionReactivar(): Action
    {
        return Action::make('reactivar')
            ->label('Reactivar')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('success')
            ->visible(fn (Paquete $record) => $record->estado === EstadoPaquete::Inactivo)
            ->requiresConfirmation()
            ->modalHeading(fn (Paquete $record) => 'Reactivar «'.$record->nombre.'»')
            ->modalDescription('Vuelve a mostrarse en el portal y a recibir reservas.')
            ->modalSubmitActionLabel('Reactivar')
            ->action(fn (Paquete $record) => self::reactivar($record));
    }

    private static function reactivar(Paquete $record): void
    {
        try {
            $record->reactivar();
        } catch (DomainException $excepcion) {
            Notification::make()
                ->title('No se reactivó el paquete')
                ->body($excepcion->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Paquete reactivado')
            ->body('«'.$record->nombre.'» volvió a «Activo».')
            ->success()
            ->send();
    }
}
