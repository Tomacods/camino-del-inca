<?php

namespace App\Filament\Resources\Excursiones\Actions;

use App\Enums\EstadoReserva;
use App\Enums\TipoNotificacion;
use App\Models\Excursion;
use App\Models\Reserva;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

// Reportar Etapa (CU-12). Es el controlador del DS-12: coordina a la excursión, que sabe cuál es la siguiente etapa, la
// registra y, con la final, finaliza las reservas. Está en una clase propia porque no tiene página: se usa en «Mis
// excursiones» y en la página de integrantes (CU-10), como Iniciar Recorrido (CU-11).
class ReportarEtapa
{
    // Pasos 1 y 2. Sólo se ofrece con el recorrido iniciado y sin la etapa final reportada (precondición): si no se
    // inició, en su lugar está la acción de Iniciar Recorrido (A1). La confirmación muestra las etapas y la que se va a
    // reportar; si el guía cancela, no se ejecuta nada.
    public static function accionReportarEtapa(): Action
    {
        return Action::make('reportarEtapa')
            ->label('Reportar etapa')
            ->icon(Heroicon::OutlinedMapPin)
            ->color('info')
            ->visible(fn (Excursion $record) => $record->admiteReporteEtapa())
            ->requiresConfirmation()
            ->modalHeading(fn (Excursion $record) => 'Reportar etapa: '.$record->obtenerSiguienteEtapa()?->nombre)
            ->modalDescription(fn (Excursion $record) => self::describirReporte($record))
            ->modalContent(fn (Excursion $record) => self::mostrarEtapas($record))
            // La etapa que el guía ve en la confirmación viaja con el pedido: si cuando llega ya no es la siguiente, no se
            // reporta otra en su lugar (ver Excursion::reportarEtapa()).
            ->schema([
                Hidden::make('id_etapa'),
            ])
            ->fillForm(fn (Excursion $record) => ['id_etapa' => $record->obtenerSiguienteEtapa()?->id_etapa])
            ->modalSubmitActionLabel('Reportar')
            ->action(fn (Excursion $record, array $data) => self::reportarEtapa($record, (int) $data['id_etapa']));
    }

    // Paso 2. Con la etapa final, adelanta lo que va a pasar al confirmar (A2).
    private static function describirReporte(Excursion $record): string
    {
        $descripcion = 'Paquete '.$record->paquete->nombre
            .' · Salida '.$record->fecha_salida->format('d/m/Y')
            .' · Última etapa reportada: '.($record->getEtapaActual()?->nombre ?? 'ninguna').'.';

        if ($record->esSiguienteEtapaFinal()) {
            $descripcion .= ' Es la etapa final: al confirmar, el recorrido termina, las reservas «'.EstadoReserva::Confirmada->value
                .'» ('.$record->getReservasConfirmadas()->count().') pasan a «'.EstadoReserva::Finalizada->value
                .'» y se avisa por correo a cada titular.';
        }

        return $descripcion;
    }

    private static function mostrarEtapas(Excursion $record): View
    {
        $datos = $record->recuperarEtapaActual();

        return view('filament.excursiones.etapas-del-recorrido', [
            'etapas' => $datos['etapas'],
            'idEtapaActual' => $datos['etapa_actual']?->id_etapa,
            'idSiguiente' => $datos['siguiente']?->id_etapa,
        ]);
    }

    // Pasos 3 a 5, A1 y A2. La excursión llega de la consulta del recurso, que sólo tiene las del guía: la de otro guía no
    // se encuentra. Antes de llegar acá, Filament vuelve a leer la excursión y a evaluar visible(); aun así, dos pedidos
    // casi juntos pueden pasar los dos ese control, y por eso la excursión valida otra vez con la fila bloqueada.
    // A3 (se corta la conexión) lo resuelve el navegador: el pedido no llega o la respuesta no vuelve, la confirmación
    // sigue abierta y el guía puede volver a enviarla; si el primero había llegado, el segundo recibe el aviso.
    private static function reportarEtapa(Excursion $record, int $idEtapa): void
    {
        try {
            $reservasFinalizadas = $record->reportarEtapa($idEtapa);
        } catch (DomainException $excepcion) {
            Notification::make()
                ->title('No se reportó la etapa')
                ->body($excepcion->getMessage())
                ->warning()
                ->send();

            return;
        }

        $encabezado = $record->paquete->nombre.' · salida '.$record->fecha_salida->format('d/m/Y').': ';

        // Paso 5.
        if (! $record->esEtapaFinal()) {
            Notification::make()
                ->title('Etapa reportada')
                ->body($encabezado.'la etapa actual es «'.$record->getEtapaActual()->nombre.'». La siguiente es «'
                    .$record->obtenerSiguienteEtapa()->nombre.'».')
                ->success()
                ->send();

            return;
        }

        // A2 4.4: Notificar Cliente (CU-13) por cada reserva finalizada. Va después de la transacción, como en CU-04: las
        // reservas ya quedaron «Finalizada», y si un correo no se puede encolar se avisa y queda anotado (A8 de CU-13), sin
        // deshacer nada.
        $reservasSinCorreo = [];

        foreach ($reservasFinalizadas as $reserva) {
            if (! Reserva::notificarCliente(TipoNotificacion::EtapaFinal, $reserva->numero_reserva)) {
                $reservasSinCorreo[] = $reserva->numero_reserva;
            }
        }

        // A2 4.5.
        Notification::make()
            ->title('Recorrido finalizado')
            ->body($encabezado.'se reportó la etapa final, «'.$record->getEtapaActual()->nombre.'». '
                .self::resumirFinalizacion($reservasFinalizadas->count(), $reservasSinCorreo))
            ->status($reservasSinCorreo === [] ? 'success' : 'warning')
            ->send();
    }

    private static function resumirFinalizacion(int $cantidadFinalizadas, array $reservasSinCorreo): string
    {
        if ($cantidadFinalizadas === 0) {
            return 'No había reservas «'.EstadoReserva::Confirmada->value.'» para finalizar.';
        }

        $resumen = $cantidadFinalizadas.' reserva(s) pasaron a «'.EstadoReserva::Finalizada->value.'»';

        if ($reservasSinCorreo === []) {
            return $resumen.' y se enviará el correo «'.TipoNotificacion::EtapaFinal->value.'» a cada titular.';
        }

        return $resumen.'. No se pudo enviar el correo «'.TipoNotificacion::EtapaFinal->value.'» de la(s) reserva(s) '
            .implode(', ', $reservasSinCorreo).': quedó anotado en el registro.';
    }
}
