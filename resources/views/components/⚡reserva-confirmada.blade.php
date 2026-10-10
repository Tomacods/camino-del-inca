<?php

use App\Enums\EstadoSaldo;
use App\Models\Pago;
use App\Models\Reserva;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

// Pantalla de vuelta de Mercado Pago y del paso 11 de Pagar Reserva (CU-15): con el pago aprobado registra la reserva y
// muestra el número de reserva y el comprobante. No hay notificaciones de Mercado Pago (webhooks): los datos de la
// reserva en curso están en la sesión del cliente, así que la reserva sólo se registra cuando el cliente vuelve acá.
new #[Title('Pago de la reserva')] class extends Component
{
    // Qué muestra: 'registrada' (la reserva recién registrada), 'sin-confirmar' (Mercado Pago no respondió: se puede
    // reintentar), 'sin-registrar' (el pago se aprobó pero la reserva no se pudo registrar) o 'sin-reserva'.
    #[Locked]
    public string $estado = 'sin-reserva';

    // El número de operación de Mercado Pago (payment_id), para que el cliente lo tenga si algo salió mal.
    #[Locked]
    public ?string $numeroOperacion = null;

    public function mount(): void
    {
        // De la dirección de vuelta sólo se toma payment_id: el resto lo puede escribir cualquiera. Sin pagar, Mercado
        // Pago vuelve con payment_id=null. Hasta 18 cifras, lo que entra en un entero.
        $idTransaccion = request()->query('payment_id');

        if (! is_string($idTransaccion) || ! preg_match('/^[0-9]{1,18}$/', $idTransaccion)) {
            $this->mostrarSinPago();

            return;
        }

        $this->numeroOperacion = $idTransaccion;
        $transaccion = Pago::consultarTransaccion($idTransaccion);

        if ($transaccion === null) {
            $this->estado = 'sin-confirmar';

            return;
        }

        // A2: el rechazo se avisa en la pantalla de pago, donde el cliente puede reintentar si la retención sigue vigente.
        if (! $transaccion['aprobada']) {
            session()->flash('pago_rechazado', true);
            $this->redirect('/reservar/pago');

            return;
        }

        $referencia = Pago::leerReferencia($transaccion['referencia']);

        if ($referencia === null) {
            $this->mostrarSinPago();

            return;
        }

        // Recargar la página de vuelta no registra otra reserva: si ya se registró la de esta retención, se muestra ésa.
        if (session('reserva_confirmada.id_retencion') === $referencia['id_retencion']) {
            $this->estado = 'registrada';

            return;
        }

        // La retención y el tipo de pago salen de la referencia, no de la sesión, que se comparte entre pestañas: si la
        // reserva en curso de la sesión es otra, este pago no la registra.
        $enCurso = session('reserva_en_curso');

        if ($enCurso === null || $enCurso['id_retencion'] !== $referencia['id_retencion']) {
            $this->estado = 'sin-registrar';

            return;
        }

        // No se compara el importe cobrado: Mercado Pago cobra el equivalente en pesos, con su cotización. El monto lo
        // aseguran la preferencia, que arma el servidor, y la referencia, que dice si se pagó la seña o el total;
        // generarReserva() registra el pago en dólares con el monto que calcula.
        try {
            $reserva = Reserva::generarReserva($enCurso, $referencia['tipo_pago'], $transaccion['medio_pago']);
        } catch (LockTimeoutException) {
            // Otra reserva se estaba registrando y no se soltó a tiempo: no se registró nada, el cliente puede reintentar.
            $this->estado = 'sin-confirmar';

            return;
        }

        // La retención venció o se liberó mientras el cliente pagaba. El pago aprobado no se devuelve desde el sistema:
        // las devoluciones son manuales, con la agencia (decisión del grupo del 13/09).
        if ($reserva === null) {
            $this->estado = 'sin-registrar';

            return;
        }

        session()->forget('reserva_en_curso');
        session(['reserva_confirmada' => [
            'id_retencion' => $referencia['id_retencion'],
            'numero_reserva' => $reserva->numero_reserva,
            'correo_electronico' => $reserva->correo_electronico,
        ]]);

        // La base no tiene dónde guardar el número de operación de Mercado Pago: queda en el registro, con la reserva.
        Log::info('Reserva '.$reserva->numero_reserva.' registrada con el pago '.$idTransaccion.' de Mercado Pago.');

        // Paso 12: acá se invoca Notificar Cliente (CU-13) con el tipo «Primera Compra», cuando exista.

        // Sin parámetros: si el cliente recarga, no se le vuelve a preguntar a Mercado Pago.
        $this->redirect('/reservar/confirmada');
    }

    // El cliente volvió sin pagar o entró a mano.
    private function mostrarSinPago(): void
    {
        if (session('reserva_confirmada') !== null) {
            $this->estado = 'registrada';
        } elseif (session('reserva_en_curso') !== null) {
            $this->redirect('/reservar/pago');
        } else {
            $this->estado = 'sin-reserva';
        }
    }

    // La reserva recién registrada, con lo que muestra la pantalla, para que la vista no haga consultas.
    #[Computed]
    public function reserva(): ?Reserva
    {
        $confirmada = session('reserva_confirmada');

        if ($this->estado !== 'registrada' || $confirmada === null) {
            return null;
        }

        $reserva = Reserva::buscarPorCorreoYNumero($confirmada['correo_electronico'], $confirmada['numero_reserva'])?->getDetalle();
        $reserva?->pagos->load('comprobante');

        return $reserva;
    }
};

?>

@php
    $reserva = $this->reserva;
    $pago = $reserva?->pagos->first();

    // La seña puede tener centavos: los montos van con dos decimales (USD 832,50).
    $dolares = fn (float $monto) => 'USD '.number_format($monto, 2, ',', '.');
@endphp

<div class="mx-auto max-w-3xl">
    @if ($reserva)
        <h1 class="text-4xl font-semibold sm:text-5xl">
            Reserva registrada. <span class="text-texto-secundario">Gracias por tu pago.</span>
        </h1>

        <x-tarjeta class="mt-8">
            <p class="text-[17px] text-texto-secundario">Tu número de reserva</p>
            <p class="mt-1 text-5xl font-semibold tabular-nums">{{ $reserva->numero_reserva }}</p>
            <p class="mt-4 text-[17px]">
                Guardalo: con tu correo ({{ $reserva->correo_electronico }}) y este número consultás tu reserva en Mi reserva.
            </p>

            <div class="mt-6 flex flex-wrap items-center gap-3 border-t border-divisor pt-5 text-[17px]">
                <x-chip-estado :estado="$reserva->estado" />
                <span class="text-texto-secundario">Falta validar los permisos de ingreso al Camino del Inca.</span>
            </div>
        </x-tarjeta>

        <x-tarjeta titulo="Comprobante" class="mt-5">
            <dl class="divide-y divide-divisor text-[17px]">
                <div class="flex justify-between gap-4 py-3">
                    <dt class="text-texto-secundario">Número</dt>
                    <dd class="tabular-nums">{{ $pago->comprobante->numero_comprobante }}</dd>
                </div>
                <div class="flex justify-between gap-4 py-3">
                    <dt class="text-texto-secundario">Emitido</dt>
                    <dd class="tabular-nums">{{ $pago->comprobante->fecha_emision->format('d/m/Y H:i') }}</dd>
                </div>
                <div class="flex justify-between gap-4 py-3">
                    <dt class="text-texto-secundario">Tipo de pago</dt>
                    <dd>{{ $pago->tipo_pago->value }}</dd>
                </div>
                <div class="flex justify-between gap-4 py-3">
                    <dt class="text-texto-secundario">Medio de pago</dt>
                    <dd class="text-right">{{ $pago->medio_pago }}</dd>
                </div>
                <div class="flex justify-between gap-4 py-3">
                    <dt class="text-texto-secundario">Monto</dt>
                    <dd class="font-semibold tabular-nums">{{ $dolares($pago->monto) }}</dd>
                </div>
            </dl>
        </x-tarjeta>

        <x-tarjeta titulo="Tu viaje" class="mt-5">
            <dl class="divide-y divide-divisor text-[17px]">
                <div class="flex justify-between gap-4 py-3">
                    <dt class="text-texto-secundario">Paquete</dt>
                    <dd class="text-right">{{ $reserva->excursion->paquete->nombre }}</dd>
                </div>
                <div class="flex justify-between gap-4 py-3">
                    <dt class="text-texto-secundario">Salida</dt>
                    <dd class="tabular-nums">{{ $reserva->excursion->getFechaSalida()->format('d/m/Y') }}</dd>
                </div>
                <div class="flex justify-between gap-4 py-3">
                    <dt class="text-texto-secundario">Integrantes</dt>
                    <dd class="text-right">
                        <ul>
                            @foreach ($reserva->excursionistas as $excursionista)
                                <li>{{ $excursionista->nombre }} {{ $excursionista->apellido }}</li>
                            @endforeach
                        </ul>
                    </dd>
                </div>
                @if ($reserva->estado_saldo === EstadoSaldo::Adeudado)
                    <div class="flex justify-between gap-4 py-3">
                        <dt class="text-texto-secundario">Saldo pendiente</dt>
                        <dd class="font-semibold tabular-nums text-ambar">{{ $dolares($reserva->calcularSaldoPendiente()) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 py-3">
                        <dt class="text-texto-secundario">Fecha límite para pagar el saldo</dt>
                        <dd class="tabular-nums">{{ $reserva->fecha_limite_saldo->format('d/m/Y') }}</dd>
                    </div>
                @endif
            </dl>
        </x-tarjeta>

        <x-boton href="/mi-reserva" class="mt-8">Ir a Mi reserva</x-boton>
    @elseif ($estado === 'sin-confirmar')
        <h1 class="text-4xl font-semibold sm:text-5xl">Pago de la reserva</h1>

        <x-aviso tipo="error" titulo="No pudimos confirmar el pago" class="mt-8">
            No pudimos consultar el pago en Mercado Pago (número de operación {{ $numeroOperacion }}) y la reserva todavía no
            se registró. Probá de nuevo en unos instantes.
        </x-aviso>

        <x-boton href="/reservar/confirmada?payment_id={{ $numeroOperacion }}" class="mt-6">Reintentar</x-boton>
    @elseif ($estado === 'sin-registrar')
        <h1 class="text-4xl font-semibold sm:text-5xl">Pago de la reserva</h1>

        <x-aviso tipo="error" titulo="No pudimos registrar la reserva" class="mt-8">
            Mercado Pago aprobó el pago, pero tus lugares ya no estaban guardados: el plazo se venció o la reserva se canceló.
            La devolución del dinero se gestiona con la agencia: comunicate con nosotros e indicá el número de operación de
            Mercado Pago, <span class="font-semibold tabular-nums">{{ $numeroOperacion }}</span>.
        </x-aviso>

        <x-boton href="/paquetes" class="mt-6">Ver los paquetes</x-boton>
    @else
        <h1 class="text-4xl font-semibold sm:text-5xl">Pago de la reserva</h1>

        <x-aviso class="mt-8">No hay ninguna reserva recién registrada.</x-aviso>

        <x-boton href="/paquetes" class="mt-6">Ver los paquetes</x-boton>
    @endif
</div>
