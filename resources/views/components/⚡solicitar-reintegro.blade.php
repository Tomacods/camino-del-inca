<?php

use App\Models\Reserva;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public string $correo = '';

    #[Locked]
    public string $numeroReserva = '';

    #[Locked]
    public int $paso = 1;

    #[Locked]
    public float $montoReintegro = 0;

    #[Locked]
    public string $mensajeError = '';

    public function mount(string $numeroReserva): void
    {
        $this->numeroReserva = $numeroReserva;
        $this->correo = session('acceso_reserva.correo', '');

        if ($this->reserva === null) {
            $this->redirect('/mi-reserva');
            return;
        }

        try {
            $this->montoReintegro = $this->reserva->calcularReintegro();
            $this->paso = 2;
        } catch (\Exception $e) {
            $this->mensajeError = $e->getMessage();
        }
    }

    public function confirmar(): void
    {
        try {
            // Se vuelve a calcular: si la reserva dejó de estar «Sin Permiso» entre un paso y otro, no se cancela
            $this->montoReintegro = $this->reserva->calcularReintegro();
            $this->reserva->ejecutarCancelacionConReintegro($this->montoReintegro, Carbon::now());
            $this->paso = 3;
        } catch (\Exception $e) {
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
<div class="mx-auto max-w-xl">
    <h1 class="text-4xl font-semibold sm:text-5xl">
        Solicitar reintegro.
        @if ($paso === 2)
            <span class="text-texto-secundario">Te devolvemos todo lo abonado.</span>
        @endif
    </h1>

    <div class="mt-8 grid gap-5">
        @if ($mensajeError && $paso === 1)
            <x-aviso tipo="error" titulo="No se puede solicitar el reintegro">
                {{ $mensajeError }}
            </x-aviso>

            <div>
                <x-boton variante="secundario" wire:click="cancelarOperacion">Volver a mi reserva</x-boton>
            </div>
        @endif

        @if ($paso === 2 && $this->reserva)
            <x-tarjeta titulo="Confirmar reintegro">
                <dl class="divide-y divide-divisor text-[17px]">
                    <div class="flex justify-between gap-4 py-3"><dt class="text-texto-secundario">Reserva</dt><dd class="font-semibold">{{ $this->reserva->numero_reserva }}</dd></div>
                    <div class="flex justify-between gap-4 py-3"><dt class="text-texto-secundario">Paquete</dt><dd class="text-right">{{ $this->reserva->excursion->paquete->nombre }}</dd></div>
                    <div class="flex justify-between gap-4 py-3"><dt class="text-texto-secundario">Salida</dt><dd class="tabular-nums">{{ $this->reserva->excursion->getFechaSalida()->format('d/m/Y') }}</dd></div>
                    <div class="flex justify-between gap-4 py-3">
                        <dt class="font-semibold">Reintegro (100 %, sin penalidad)</dt>
                        <dd class="font-semibold tabular-nums text-accion">USD {{ number_format($montoReintegro, 0, ',', '.') }}</dd>
                    </div>
                </dl>

                <x-aviso class="mt-5">La reserva se cancela y los lugares se liberan. No se puede deshacer.</x-aviso>

                <div class="mt-5 flex flex-wrap gap-3">
                    <x-boton wire:click="confirmar">Confirmar reintegro</x-boton>
                    <x-boton variante="secundario" wire:click="cancelarOperacion">Volver</x-boton>
                </div>
            </x-tarjeta>
        @endif

        @if ($paso === 3)
            <x-aviso titulo="Reserva cancelada con reintegro del 100 %">
                Registramos el reintegro de USD {{ number_format($montoReintegro, 0, ',', '.') }}.
            </x-aviso>

            <div>
                <x-boton wire:click="cancelarOperacion">Volver a mi reserva</x-boton>
            </div>
        @endif
    </div>
</div>