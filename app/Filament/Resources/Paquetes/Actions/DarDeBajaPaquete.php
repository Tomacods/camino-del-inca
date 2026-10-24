<?php

namespace App\Filament\Resources\Paquetes\Actions;

use App\Enums\EstadoPaquete;
use App\Models\Paquete;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

// Eliminar Paquete (CU-03, R-03). Es una baja lógica: el paquete pasa a «Inactivo». La acción sólo coordina; el paquete
// decide si se puede dar de baja.
class DarDeBajaPaquete
{
    public static function accionDarDeBaja(): Action
    {
        return Action::make('darDeBaja')
            ->label('Dar de baja')
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('danger')
            ->visible(fn (Paquete $record) => $record->estado === EstadoPaquete::Activo)
            ->requiresConfirmation()
            ->modalHeading(fn (Paquete $record) => 'Dar de baja «'.$record->nombre.'»')
            ->modalDescription('Deja de mostrarse en el portal y no recibe reservas. No se borra: se puede reactivar después.')
            ->modalSubmitActionLabel('Dar de baja')
            ->action(fn (Paquete $record) => self::darDeBaja($record));
    }

    // Si tiene reservas activas, el paquete no se da de baja y se avisa el motivo.
    private static function darDeBaja(Paquete $record): void
    {
        try {
            $record->darDeBaja();
        } catch (DomainException $excepcion) {
            Notification::make()
                ->title('No se dio de baja el paquete')
                ->body($excepcion->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Paquete dado de baja')
            ->body('«'.$record->nombre.'» quedó «Inactivo».')
            ->success()
            ->send();
    }
}
