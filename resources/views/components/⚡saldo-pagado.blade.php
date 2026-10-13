<?php

use App\Enums\EstadoSaldo;
use App\Enums\TipoPago;
use App\Models\Pago;
use App\Models\Reserva;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

// Pantalla de vuelta de Mercado Pago de Pagar Saldo Pendiente (CU-16), pasos 7 a 10: con el pago aprobado registra el
// pago «Saldo» y muestra el comprobante. No hay notificaciones de Mercado Pago (webhooks), como en CU-15: el pago se
// registra cuando el cliente vuelve acá. A diferencia de CU-15, no depende de la sesión: la reserva ya existe y la
// referencia del pago dice cuál es.
new #[Title('Pago del saldo')] class extends Component
{
    #[Locked]
    public string $numeroReserva = '';

    // Qué muestra: 'registrado' (el pago del saldo recién registrado), 'sin-confirmar' (Mercado Pago no respondió: se
    // puede reintentar), 'no-corresponde' (el pago aprobado no es del saldo de esta reserva), 'ya-abonado' (el pago se
    // aprobó pero el saldo ya figuraba pagado) o 'sin-registrar' (el pago se aprobó pero la reserva ya no estaba
    // confirmada con el saldo adeudado). Vacío mientras manda al cliente a otra pantalla.
    #[Locked]
    public string $estado = '';

    // El número de operación de Mercado Pago (payment_id), para que el cliente lo tenga si algo salió mal.
    #[Locked]
    public ?string $numeroOperacion = null;

    public function mount(string $numeroReserva): void
    {
        $this->numeroReserva = $numeroReserva;

        // De la dirección de vuelta sólo se toma payment_id: el resto lo puede escribir cualquiera. Sin pagar, Mercado
        // Pago vuelve con payment_id=null. Hasta 18 cifras, lo que entra en un entero.
        $idTransaccion = request()->query('payment_id');

        if (! is_string($idTransaccion) || ! preg_match('/^[0-9]{1,18}$/', $idTransaccion)) {
            $this->mostrarSinPago();

            return;
        }

        $this->numeroOperacion = $idTransaccion;

        // La misma vuelta otra vez (atrás y adelante en el navegador, o en dos pestañas): si este pago ya se registró en
        // esta sesión, se muestra el comprobante, sin consultar de nuevo ni tomarlo por un pago doble.
        if ($this->saldoRecienPagado() && session('saldo_pagado.numero_operacion') === $idTransaccion) {
            $this->redirect('/mi-reserva/'.$numeroReserva.'/saldo-pagado');

            return;
        }

        $transaccion = Pago::consultarTransaccion($idTransaccion);

        if ($transaccion === null) {
            $this->estado = 'sin-confirmar';

            return;
        }

        // A4: el rechazo se avisa en la pantalla de pago del saldo, donde el cliente puede reintentar.
        if (! $transaccion['aprobada']) {
            session()->flash('pago_saldo_rechazado', true);
            $this->redirect('/mi-reserva/'.$numeroReserva.'/pagar-saldo');

            return;
        }

        // La reserva sale de la referencia, que armó el servidor, y tiene que ser la de la dirección. Se busca sin el
        // correo de la sesión: si el cliente pagó y la sesión se perdió, el pago igual se registra.
        $reserva = Pago::leerReferenciaSaldo($transaccion['referencia']) === $numeroReserva
            ? Reserva::firstWhere('numero_reserva', $numeroReserva)
            : null;

        if ($reserva === null) {
            $this->estado = 'no-corresponde';

            return;
        }

        // No se compara el importe cobrado: viene en pesos. Lo aseguran el pedido de cobro, que arma el servidor, y la
        // referencia; registrarPagoSaldo() registra el saldo que calcula en este momento.
        $pago = Pago::registrarPagoSaldo($reserva, $transaccion['medio_pago']);

        // La reserva ya no tenía saldo para pagar. El pago aprobado no se devuelve desde el sistema: las devoluciones son
        // manuales, con la agencia (decisión del grupo del 13/09). Si el saldo ya figura «Abonado», puede ser un segundo
        // pago (dos pestañas) o la misma vuelta repetida desde otra sesión: la base no guarda el número de operación y no
        // se pueden distinguir, así que el aviso no afirma que el pago quedó sin registrar.
        if ($pago === null) {
            $this->estado = $reserva->fresh()?->estado_saldo === EstadoSaldo::Abonado ? 'ya-abonado' : 'sin-registrar';

            return;
        }

        // La base no tiene dónde guardar el número de operación de Mercado Pago: queda en el registro, con la reserva.
        Log::info('Saldo de la reserva '.$numeroReserva.' registrado con el pago '.$idTransaccion.' de Mercado Pago.');

        // Paso 11: acá se invoca Notificar Cliente (CU-13) con el tipo «Pago de Saldo», cuando exista.

        session(['saldo_pagado' => [
            'numero_reserva' => $numeroReserva,
            'numero_operacion' => $idTransaccion,
        ]]);

        // Sin parámetros: si el cliente recarga, no se le vuelve a preguntar a Mercado Pago.
        $this->redirect('/mi-reserva/'.$numeroReserva.'/saldo-pagado');
    }

    // El cliente volvió sin pagar o entró a mano. Si en esta sesión se acaba de registrar el saldo de esta reserva, se
    // muestra el comprobante; si no, la pantalla de pago del saldo se ocupa del resto (sin acceso, lleva a Mi reserva).
    private function mostrarSinPago(): void
    {
        if ($this->saldoRecienPagado()) {
            $this->estado = 'registrado';
        } else {
            $this->redirect('/mi-reserva/'.$this->numeroReserva.'/pagar-saldo');
        }
    }

    private function saldoRecienPagado(): bool
    {
        return session('saldo_pagado.numero_reserva') === $this->numeroReserva;
    }

    // La reserva con sus pagos y comprobantes, para que la vista no haga consultas. Sólo si la sesión tiene el acceso a
    // esta reserva (el correo con que se consultó en Mi reserva): sin acceso, la pantalla no muestra sus datos.
    #[Computed]
    public function reserva(): ?Reserva
    {
        if ($this->estado !== 'registrado') {
            return null;
        }

        $reserva = Reserva::buscarPorCorreoYNumero(session('acceso_reserva.correo', ''), $this->numeroReserva)?->getDetalle();
        $reserva?->pagos->load('comprobante');

        return $reserva;
    }

    #[Computed]
    public function pagoSaldo(): ?Pago
    {
        return $this->reserva?->pagos->firstWhere('tipo_pago', TipoPago::Saldo);
    }
};

?>

@php
    $reserva = $this->reserva;
    $pago = $this->pagoSaldo;

    // El saldo puede tener centavos: los montos van con dos decimales (USD 832,50).
    $dolares = fn (float $monto) => 'USD '.number_format($monto, 2, ',', '.');
@endphp

<div class="mx-auto max-w-3xl">
    @if ($estado === 'registrado' && $pago)
        <h1 class="text-4xl font-semibold sm:text-5xl">
            Saldo pagado. <span class="text-texto-secundario">Gracias por tu pago.</span>
        </h1>

        <x-tarjeta class="mt-8">
            <p class="text-[17px] text-texto-secundario">Reserva</p>
            <p class="mt-1 text-5xl font-semibold tabular-nums">{{ $reserva->numero_reserva }}</p>

            <div class="mt-6 flex flex-wrap items-center gap-3 border-t border-divisor pt-5 text-[17px]">
                <x-chip-estado :estado="$reserva->estado" />
                <span>El saldo quedó «{{ $reserva->estado_saldo->value }}»: no queda nada por pagar.</span>
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

        <x-boton href="/mi-reserva" class="mt-8">Ir a Mi reserva</x-boton>
    @elseif ($estado === 'registrado')
        {{-- La sesión no tiene el acceso a esta reserva: se confirma el pago sin mostrar los datos de la reserva. --}}
        <h1 class="text-4xl font-semibold sm:text-5xl">
            Saldo pagado. <span class="text-texto-secundario">Gracias por tu pago.</span>
        </h1>

        <x-aviso titulo="Registramos tu pago" class="mt-8">
            El pago del saldo de la reserva N.º <span class="tabular-nums">{{ $numeroReserva }}</span> quedó registrado. Para
            ver el detalle, consultá tu reserva en Mi reserva con tu correo y el número de reserva.
        </x-aviso>

        <x-boton href="/mi-reserva" class="mt-6">Ir a Mi reserva</x-boton>
    @elseif ($estado === 'sin-confirmar')
        <h1 class="text-4xl font-semibold sm:text-5xl">Pago del saldo</h1>

        <x-aviso tipo="error" titulo="No pudimos confirmar el pago" class="mt-8">
            No pudimos consultar el pago en Mercado Pago (número de operación {{ $numeroOperacion }}) y el pago del saldo
            todavía no se registró. Probá de nuevo en unos instantes.
        </x-aviso>

        <x-boton href="/mi-reserva/{{ $numeroReserva }}/saldo-pagado?payment_id={{ $numeroOperacion }}" class="mt-6">Reintentar</x-boton>
    @elseif ($estado === 'ya-abonado')
        <h1 class="text-4xl font-semibold sm:text-5xl">Pago del saldo</h1>

        <x-aviso titulo="El saldo ya estaba pagado" class="mt-8">
            El saldo de la reserva N.º <span class="tabular-nums">{{ $numeroReserva }}</span> ya figura pagado, así que este
            pago no se registró otra vez. Si volviste de Mercado Pago más de una vez con el mismo pago, no tenés que hacer
            nada: lo ves en Mi reserva. Si pagaste el saldo dos veces, la devolución se gestiona con la agencia: comunicate
            con nosotros e indicá el número de operación de Mercado Pago,
            <span class="font-semibold tabular-nums">{{ $numeroOperacion }}</span>.
        </x-aviso>

        <x-boton href="/mi-reserva" class="mt-6">Ir a Mi reserva</x-boton>
    @elseif ($estado === 'sin-registrar')
        <h1 class="text-4xl font-semibold sm:text-5xl">Pago del saldo</h1>

        <x-aviso tipo="error" titulo="No pudimos registrar el pago" class="mt-8">
            Mercado Pago aprobó el pago, pero la reserva ya no estaba confirmada con el saldo pendiente: por ejemplo, se
            canceló mientras pagabas. La devolución del dinero se gestiona con la agencia: comunicate con nosotros e indicá
            el número de operación de Mercado Pago, <span class="font-semibold tabular-nums">{{ $numeroOperacion }}</span>.
        </x-aviso>

        <x-boton href="/mi-reserva" class="mt-6">Ir a Mi reserva</x-boton>
    @elseif ($estado === 'no-corresponde')
        <h1 class="text-4xl font-semibold sm:text-5xl">Pago del saldo</h1>

        <x-aviso tipo="error" titulo="No pudimos registrar el pago" class="mt-8">
            Mercado Pago aprobó el pago, pero no corresponde al saldo de la reserva N.º
            <span class="tabular-nums">{{ $numeroReserva }}</span> y no lo registramos. Comunicate con la agencia e indicá el
            número de operación de Mercado Pago, <span class="font-semibold tabular-nums">{{ $numeroOperacion }}</span>.
        </x-aviso>

        <x-boton href="/mi-reserva" class="mt-6">Ir a Mi reserva</x-boton>
    @endif
</div>
