<?php

namespace App\Filament\Resources\Excursiones\Actions;

use App\Models\Excursion;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

// Iniciar Recorrido (CU-11). No tiene DSS ni DS: la acción sólo coordina. La excursión decide si el recorrido se puede
// iniciar y registra la fecha y la hora. Está en una clase propia porque no tiene página: se usa en «Mis excursiones» y
// en la página de integrantes (CU-10).
class IniciarRecorrido
{
    // Pasos 1 a 3 y A3: la confirmación muestra la excursión; si el guía cancela, no se ejecuta nada.
    public static function accionIniciarRecorrido(): Action
    {
        return Action::make('iniciarRecorrido')
            ->label('Iniciar recorrido')
            ->icon(Heroicon::OutlinedFlag)
            ->color('success')
            ->visible(fn (Excursion $record) => $record->admiteInicioRecorrido(now()))
            ->requiresConfirmation()
            ->modalHeading('Iniciar recorrido')
            ->modalDescription(fn (Excursion $record) => 'Paquete '.$record->paquete->nombre
                .' · Recorrido '.$record->paquete->recorrido->nombre
                .' · Salida '.$record->fecha_salida->format('d/m/Y')
                .'. Al confirmar, queda registrado que el recorrido empieza ahora.')
            ->modalSubmitActionLabel('Iniciar')
            ->action(fn (Excursion $record) => self::iniciarRecorrido($record));
    }

    // Paso 4, A1 y A2. La excursión llega de la consulta del recurso, que sólo tiene las del guía: en la tabla y en la página
    // de integrantes, la de otro guía no se encuentra. Antes de llegar acá, Filament vuelve a leer la excursión y a evaluar
    // visible(); aun así, dos pedidos casi juntos pueden pasar los dos ese control, y por eso la excursión valida otra vez
    // con la fila bloqueada: el segundo recibe el aviso de A1.
    private static function iniciarRecorrido(Excursion $record): void
    {
        try {
            $record->iniciarRecorrido();
        } catch (DomainException $excepcion) {
            Notification::make()
                ->title('No se inició el recorrido')
                ->body($excepcion->getMessage())
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Recorrido iniciado')
            ->body($record->paquete->nombre.' · salida '.$record->fecha_salida->format('d/m/Y').': el recorrido quedó iniciado el '
                .$record->fecha_hora_inicio_recorrido->format('d/m/Y').' a las '.$record->fecha_hora_inicio_recorrido->format('H:i').'.')
            ->success()
            ->send();
    }
}
