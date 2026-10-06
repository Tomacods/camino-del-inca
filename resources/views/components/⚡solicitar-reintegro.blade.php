<?php

use App\Models\Reserva;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    public string $correo = '';
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
    @if ($mensajeError && $paso === 1)
        <div class="rounded-lg border border-peligro bg-tarjeta p-5">
            <h2 class="text-lg font-bold text-peligro">No se puede solicitar el reintegro</h2>
            <p class="mt-2 text-sm text-texto">{{ $mensajeError }}</p>
            <button type="button" wire:click="cancelarOperacion" class="mt-4 rounded border border-borde px-4 py-2 text-sm font-semibold text-texto">Volver a mi reserva</button>
        </div>
    @endif

    @if ($paso === 2 && $this->reserva)
        <section class="rounded-lg border border-borde bg-tarjeta p-5">
            <h2 class="mb-4 text-lg font-bold">Solicitar reintegro</h2>
            <dl class="mb-6 divide-y divide-borde text-sm">
                <div class="flex justify-between py-2"><dt class="text-texto-secundario">Reserva</dt><dd class="font-semibold">{{ $this->reserva->numero_reserva }}</dd></div>
                <div class="flex justify-between py-2"><dt class="text-texto-secundario">Paquete</dt><dd>{{ $this->reserva->excursion->paquete->nombre }}</dd></div>
                <div class="flex justify-between py-2"><dt class="text-texto-secundario">Salida</dt><dd>{{ $this->reserva->excursion->getFechaSalida()->format('d/m/Y') }}</dd></div>
                <div class="flex justify-between pt-4">
                    <dt class="font-semibold text-texto">Reintegro (100 %, sin penalidad)</dt>
                    <dd class="font-semibold text-inca">USD {{ number_format($montoReintegro, 0, ',', '.') }}</dd>
                </div>
            </dl>
            <p class="mb-6 text-sm text-texto">La reserva se cancela y los lugares se liberan. No se puede deshacer.</p>
            <div class="flex flex-wrap gap-3">
                <button type="button" wire:click="confirmar" class="rounded border border-peligro bg-tarjeta px-4 py-2 text-sm font-semibold text-peligro hover:bg-peligro hover:text-fondo">Confirmar reintegro</button>
                <button type="button" wire:click="cancelarOperacion" class="rounded border border-borde px-4 py-2 text-sm font-semibold text-texto hover:bg-fondo">Volver</button>
            </div>
        </section>
    @endif

    @if ($paso === 3)
        <section class="rounded-lg border border-inca bg-tarjeta p-8 text-center">
            <h2 class="mb-2 text-xl font-bold text-inca">Reserva cancelada con reintegro del 100 %</h2>
            <p class="mb-4 text-sm text-texto">USD {{ number_format($montoReintegro, 0, ',', '.') }}</p>
            <button type="button" wire:click="cancelarOperacion" class="rounded bg-inca px-6 py-2 font-semibold text-fondo">Volver a mi reserva</button>
        </section>
    @endif
</div>