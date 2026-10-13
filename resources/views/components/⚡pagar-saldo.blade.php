<?php

use App\Models\Pago;
use App\Models\Reserva;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

// Pantalla de Pagar Saldo Pendiente (CU-16), pasos 1 a 6: muestra el saldo y la fecha límite y deriva al cliente a
// Mercado Pago. También cancela (A1) y avisa si no hay saldo para pagar (A2) o si venció el plazo (A3). Se entra desde
// Mi reserva, como Cancelar Reserva: el correo sale de la sesión y el número, de la dirección. La vuelta de Mercado Pago
// la recibe la pantalla saldo-pagado.
new #[Title('Pagar saldo')] class extends Component
{
    #[Locked]
    public string $numeroReserva = '';

    // El aviso de A2 o A3 cuando el saldo no se puede pagar; null si se puede. Lo decide Reserva::iniciarPagoSaldo().
    #[Locked]
    public ?string $aviso = null;

    // Lo que devolvió iniciarPagoSaldo(), para mostrarlo. Al confirmar se vuelve a pedir: el monto que se cobra nunca
    // sale de una propiedad del componente.
    #[Locked]
    public float $saldoPendiente = 0;

    #[Locked]
    public ?Carbon $fechaLimiteSaldo = null;

    // Si Mercado Pago rechazó el pago (A4): lo avisa la pantalla de vuelta al mandar al cliente acá.
    #[Locked]
    public bool $pagoRechazado = false;

    // Si no se pudo conectar con Mercado Pago al confirmar el pago.
    #[Locked]
    public bool $errorMercadoPago = false;

    public function mount(string $numeroReserva): void
    {
        $this->numeroReserva = $numeroReserva;
        $this->pagoRechazado = session('pago_saldo_rechazado', false);

        $this->pagarSaldo(session('acceso_reserva.correo', ''), $numeroReserva);
    }

    // Pasos 1 a 4 (pagarSaldo del DS-16), con el correo con el que el cliente consultó su reserva en Mi reserva. Sin ese
    // acceso, o si el número no es de ese correo, vuelve a Mi reserva.
    protected function pagarSaldo(string $correo, string $numeroReserva): void
    {
        $reserva = Reserva::buscarPorCorreoYNumero($correo, $numeroReserva);

        if ($reserva === null) {
            $this->redirect('/mi-reserva');

            return;
        }

        $this->comprobarSaldo($reserva);
    }

    // Pasos 5 y 6 (confirmarPago y derivarPago del DS-16). La reserva pudo cambiar con la pantalla abierta (se pagó en
    // otra pestaña, venció el plazo): se comprueba de nuevo y el monto sale de esa comprobación, nunca del navegador.
    public function confirmarPago(): void
    {
        $this->errorMercadoPago = false;
        $reserva = $this->reserva;

        if ($reserva === null) {
            $this->redirect('/mi-reserva');

            return;
        }

        $saldo = $this->comprobarSaldo($reserva);

        if ($saldo === null) {
            return;
        }

        $excursion = $reserva->excursion;

        $datosPago = [
            // La fecha va con guiones: Mercado Pago saca las barras de la descripción, como en CU-15.
            'descripcion' => $excursion->paquete->nombre.', salida '.$excursion->getFechaSalida()->format('d-m-Y')
                .' (saldo de la reserva '.$reserva->numero_reserva.')',
            'referencia' => Pago::armarReferenciaSaldo($reserva->numero_reserva),
            'direccion_vuelta' => url('/mi-reserva/'.$reserva->numero_reserva.'/saldo-pagado'),
            // Vence a los minutos de la configuración o en la fecha límite del saldo, lo que llegue antes. No hay una
            // retención que lo limite, y un pedido abierto por semanas se podría pagar con el saldo ya abonado.
            'vence' => now()->addMinutes(config('reserva.minutos_vigencia_pago_saldo'))->min($saldo['fecha_limite_saldo']),
        ];

        try {
            $direccionMercadoPago = Pago::derivarPago($datosPago, $saldo['saldo_pendiente']);
        } catch (Exception) {
            // El error ya quedó en el registro: lo anota Pago. Acá sólo se le avisa al cliente.
            $this->errorMercadoPago = true;

            return;
        }

        $this->redirect($direccionMercadoPago);
    }

    // A1: el cliente cancela. No se tocó nada, así que el saldo sigue «Adeudado».
    public function cancelar(): void
    {
        $this->redirect('/mi-reserva');
    }

    // Se busca en cada pedido con el correo de la sesión: si el cliente ya no tiene el acceso a esta reserva, la pantalla
    // deja de actuar sobre ella.
    #[Computed]
    public function reserva(): ?Reserva
    {
        return Reserva::buscarPorCorreoYNumero(session('acceso_reserva.correo', ''), $this->numeroReserva)?->getDetalle();
    }

    // Le pregunta a la reserva si el saldo se puede pagar. Guarda el saldo y la fecha límite para mostrarlos, o el aviso
    // de A2 o A3, y devuelve lo mismo que iniciarPagoSaldo(), o null si no se puede pagar.
    private function comprobarSaldo(Reserva $reserva): ?array
    {
        try {
            $saldo = $reserva->iniciarPagoSaldo();
        } catch (DomainException $excepcion) {
            // En A3 la reserva se cancela por falta de pago, pero eso lo hace CU-22: esta pantalla sólo informa.
            $this->aviso = $excepcion->getMessage();

            return null;
        }

        $this->aviso = null;
        $this->saldoPendiente = $saldo['saldo_pendiente'];
        $this->fechaLimiteSaldo = $saldo['fecha_limite_saldo'];

        return $saldo;
    }
};

?>

@php
    $reserva = $this->reserva;

    // El saldo puede tener centavos: los montos de esta pantalla van con dos decimales (USD 832,50).
    $dolares = fn (float $monto) => 'USD '.number_format($monto, 2, ',', '.');
@endphp

<div class="mx-auto max-w-3xl">
    {{-- Sin el acceso a la reserva no se muestra nada: mount() o confirmarPago() ya mandaron al cliente a Mi reserva. --}}
    @if ($aviso !== null)
        <h1 class="text-4xl font-semibold sm:text-5xl">Pago del saldo</h1>

        <x-aviso titulo="No se puede pagar el saldo" class="mt-8">{{ $aviso }}</x-aviso>

        <x-boton href="/mi-reserva" class="mt-6">Volver a Mi reserva</x-boton>
    @elseif ($reserva !== null)
        @if ($pagoRechazado)
            <x-aviso tipo="error" titulo="El pago fue rechazado" class="mb-8">
                Mercado Pago rechazó el pago y el saldo sigue pendiente. Podés intentarlo de nuevo.
            </x-aviso>
        @endif

        <h1 class="text-4xl font-semibold sm:text-5xl">Pago del saldo</h1>

        <x-tarjeta titulo="Tu reserva" class="mt-8">
            <dl class="divide-y divide-divisor text-[17px]">
                <div class="flex justify-between gap-4 py-3">
                    <dt class="text-texto-secundario">Número de reserva</dt>
                    <dd class="tabular-nums">{{ $reserva->numero_reserva }}</dd>
                </div>
                <div class="flex justify-between gap-4 py-3">
                    <dt class="text-texto-secundario">Paquete</dt>
                    <dd class="text-right">{{ $reserva->excursion->paquete->nombre }}</dd>
                </div>
                <div class="flex justify-between gap-4 py-3">
                    <dt class="text-texto-secundario">Salida</dt>
                    <dd class="tabular-nums">{{ $reserva->excursion->getFechaSalida()->format('d/m/Y') }}</dd>
                </div>
            </dl>
        </x-tarjeta>

        <x-tarjeta titulo="Saldo" class="mt-5">
            <dl class="divide-y divide-divisor text-[17px]">
                <div class="flex justify-between gap-4 py-3">
                    <dt class="text-texto-secundario">Monto total de la reserva</dt>
                    <dd class="tabular-nums">{{ $dolares($reserva->calcularMontoTotal()) }}</dd>
                </div>
                @foreach ($reserva->pagos as $pago)
                    <div class="flex justify-between gap-4 py-3">
                        <dt class="text-texto-secundario tabular-nums">
                            Abonado ({{ mb_strtolower($pago->tipo_pago->value) }}, {{ $pago->fecha->format('d/m/Y') }})
                        </dt>
                        <dd class="tabular-nums">{{ $dolares($pago->monto) }}</dd>
                    </div>
                @endforeach
                <div class="flex justify-between gap-4 py-3">
                    <dt class="text-texto-secundario">Fecha límite para pagar el saldo</dt>
                    <dd class="tabular-nums">{{ $fechaLimiteSaldo->format('d/m/Y') }}</dd>
                </div>
            </dl>

            <div class="mt-2 flex flex-wrap items-end justify-between gap-x-4 gap-y-1 border-t border-divisor pt-5">
                <p class="text-[17px] font-semibold">Saldo pendiente</p>
                <p class="text-4xl font-semibold tabular-nums text-ambar">{{ $dolares($saldoPendiente) }}</p>
            </div>
            <p class="mt-3 text-sm text-texto-secundario">
                Mercado Pago te cobra el equivalente en pesos, con su cotización del momento.
            </p>
        </x-tarjeta>

        @if ($errorMercadoPago)
            <x-aviso tipo="error" titulo="No pudimos conectar con Mercado Pago" class="mt-5">
                El saldo sigue pendiente. Probá de nuevo en unos instantes.
            </x-aviso>
        @endif

        <div class="mt-8 flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
            <x-boton variante="secundario" wire:click="cancelar">Cancelar</x-boton>
            <x-boton wire:click="confirmarPago">Pagar el saldo con Mercado Pago</x-boton>
        </div>
    @endif
</div>
