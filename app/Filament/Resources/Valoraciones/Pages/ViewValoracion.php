<?php

namespace App\Filament\Resources\Valoraciones\Pages;

use App\Filament\Resources\Reservas\ReservaResource;
use App\Filament\Resources\Valoraciones\ValoracionResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

// CU-09 Consultar Valoración
class ViewValoracion extends ViewRecord
{
    protected static string $resource = ValoracionResource::class;

    // Desde el detalle se puede abrir la reserva valorada, en la sección Reservas del panel.
    protected function getHeaderActions(): array
    {
        return [
            Action::make('verReserva')
                ->label('Ver la reserva')
                ->icon(Heroicon::OutlinedTicket)
                ->color('gray')
                ->url(fn () => ReservaResource::getUrl('view', ['record' => $this->getRecord()->id_reserva])),
        ];
    }
}
