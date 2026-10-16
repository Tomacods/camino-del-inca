<?php

use App\Models\Reserva;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

new #[Title('Cancelar reserva')] class extends Component
{
    // #[Locked]: el navegador no puede modificar estas propiedades
    #[Locked]
    public string $correo = '';

    #[Locked]
    public string $numeroReserva = '';

    #[Locked]
    public int $paso = 1;

    #[Locked]
    public float $montoReembolso = 0;

    #[Locked]
    public string $mensajeError = '';

public function mount(string $numeroReserva): void
{
    $this->numeroReserva = $numeroReserva;
    $this->correo = session('acceso_reserva.correo', '');

    // Sin acceso válido (o número que no corresponde al correo), vuelve al formulario
    if ($this->reserva === null) {
        $this->redirect('/mi-reserva');
        return;
    }

    try {
        // El modelo calcula y se valida a sí mismo
        $this->montoReembolso = $this->reserva->calcularReembolso(Carbon::now());
        $this->paso = 2;
    } catch (\Exception $e) {
        $this->mensajeError = $e->getMessage();
    }
}

    public function confirmar(): void
    {
        if ($this->reserva === null) {
            return;
        }

        try {
            $this->reserva->confirmarCancelacion(Carbon::now());
            Reserva::notificarCliente(TipoNotificacion::CancelacionReserva, $this->reserva->numero_reserva);
            $this->paso = 3;
        } catch (\Exception $e) {
            // Si el estado cambió entre un paso y otro, se muestra el aviso en vez de un error 500
            $this->mensajeError = $e->getMessage();
            $this->paso = 1;
        }
    }

public function cancelarOperacion()
{
    return $this->redirect('/mi-reserva');
}

    #[Computed]
    public function reserva(): ?Reserva
    {
        if (empty($this->correo) || empty($this->numeroReserva)) {
            return null;
        }

        return Reserva::buscarPorCorreoYNumero($this->correo, $this->numeroReserva);
    }
};
?>
@php
    $diasLimite = config('reserva.dias_anticipacion_reembolso');
    $porcentajeReembolso = (int) round(config('reserva.porcentaje_reembolso') * 100);
@endphp

<div class="mx-auto max-w-xl">
    <h1 class="text-4xl font-semibold sm:text-5xl">
        Cancelar reserva.
        @if ($paso === 2)
            <span class="text-texto-secundario">Revisá cuánto se te devuelve.</span>
        @endif
    </h1>

    <div class="mt-8 grid gap-5">
        @if ($mensajeError && $paso === 1)
            <x-aviso tipo="error" titulo="No se puede cancelar">
                {{ $mensajeError }}
            </x-aviso>

            <div>
                <x-boton variante="secundario" wire:click="cancelarOperacion">Volver a mi reserva</x-boton>
            </div>
        @endif

        @if ($paso === 2 && $this->reserva)
            <x-tarjeta titulo="Confirmar cancelación">
                <dl class="divide-y divide-divisor text-[17px]">
                    <div class="flex justify-between gap-4 py-3"><dt class="text-texto-secundario">Reserva</dt><dd class="font-semibold">{{ $this->reserva->numero_reserva }}</dd></div>
                    <div class="flex justify-between gap-4 py-3"><dt class="text-texto-secundario">Paquete</dt><dd class="text-right">{{ $this->reserva->excursion->paquete->nombre }}</dd></div>
                    <div class="flex justify-between gap-4 py-3"><dt class="text-texto-secundario">Salida</dt><dd class="tabular-nums">{{ $this->reserva->excursion->getFechaSalida()->format('d/m/Y') }}</dd></div>
                    <div class="flex justify-between gap-4 py-3">
                        <dt class="font-semibold">Monto a reembolsar</dt>
                        <dd class="font-semibold tabular-nums {{ $montoReembolso > 0 ? 'text-accion' : 'text-texto-secundario' }}">USD {{ number_format($montoReembolso, 2, ',', '.') }}</dd>
                    </div>
                </dl>

                @if ($montoReembolso == 0)
                    <x-aviso class="mt-5">Faltan {{ $diasLimite }} días o menos. No genera devolución.</x-aviso>
                @else
                    <x-aviso class="mt-5">Te corresponde un reembolso del {{ $porcentajeReembolso }} %.</x-aviso>
                @endif

                <div class="mt-5 flex flex-wrap gap-3">
                    {{-- Mientras se procesa queda deshabilitado, para que no se cancele dos veces. --}}
                    <x-boton wire:click="confirmar" wire:loading.attr="disabled" wire:target="confirmar">
                        <span wire:loading.remove wire:target="confirmar">Sí, cancelar reserva</span>
                        <span wire:loading wire:target="confirmar">Cancelando la reserva…</span>
                    </x-boton>
                    <x-boton variante="secundario" wire:click="cancelarOperacion">No, me arrepentí</x-boton>
                </div>
            </x-tarjeta>
        @endif

        @if ($paso === 3)
            {{-- Recibe el foco al aparecer, para que el lector de pantalla anuncie cómo terminó la operación. --}}
            <x-aviso titulo="¡Reserva cancelada con éxito!" tabindex="-1" x-init="$el.focus()" />

            <div>
                <x-boton wire:click="cancelarOperacion">Volver a mi reserva</x-boton>
            </div>
        @endif
    </div>
</div>