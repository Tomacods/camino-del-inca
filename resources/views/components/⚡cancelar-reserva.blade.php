<?php

use App\Models\Reserva;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

new class extends Component
{
    public string $correo = '';
    public string $numeroReserva = '';

    // #[Locked]: el navegador no puede modificar estas propiedades
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

    $this->buscar();
}

    public function buscar(): void
    {
        $this->validate([
            'correo' => 'required|email',
            'numeroReserva' => ['required', 'regex:/^\d{6}-\d$/'],
        ], [
            'correo.required' => 'Ingresá tu correo electrónico.',
            'correo.email' => 'El correo no tiene un formato válido.',
            'numeroReserva.required' => 'Ingresá el número de reserva.',
            'numeroReserva.regex' => 'El número de reserva no tiene un formato válido.',
        ]);

        $this->mensajeError = '';

        if ($this->reserva === null) {
            $this->addError('numeroReserva', 'No encontramos una reserva con esos datos.');
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

<div class="grid gap-6 md:grid-cols-[300px_1fr]">
    {{-- COLUMNA IZQUIERDA: Buscador --}}
    <section class="self-start rounded-lg border border-borde bg-tarjeta p-5">
        <h1 class="mb-4 font-bold">Cancelar mi reserva</h1>

        <form wire:submit="buscar" class="space-y-4">
            <div>
                <label for="correo" class="block text-sm text-texto">Correo electrónico</label>
                <input id="correo" type="email" wire:model="correo" @if($paso > 1) disabled @endif
                       class="mt-1 w-full rounded border border-borde bg-fondo px-3 py-2 text-texto disabled:opacity-50">
                @error('correo') <p class="mt-1 text-sm text-peligro">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="numeroReserva" class="block text-sm text-texto">Número de reserva</label>
                <input id="numeroReserva" type="text" wire:model="numeroReserva" placeholder="000124-7" @if($paso > 1) disabled @endif
                       class="mt-1 w-full rounded border border-borde bg-fondo px-3 py-2 text-texto placeholder-texto-secundario disabled:opacity-50">
                @error('numeroReserva') <p class="mt-1 text-sm text-peligro">{{ $message }}</p> @enderror
            </div>

            @if($paso === 1)
                <button type="submit" class="w-full rounded bg-inca py-2 font-semibold text-fondo">Consultar</button>
            @endif
        </form>
    </section>

    {{-- COLUMNA DERECHA: Resultados --}}
    <div>
        @if ($mensajeError && $paso === 1)
            <div class="rounded-lg border border-peligro bg-tarjeta p-5">
                <h2 class="text-lg font-bold text-peligro">No se puede cancelar</h2>
                <p class="mt-2 text-sm text-texto">{{ $mensajeError }}</p>
            </div>
        @endif

        @if ($paso === 2 && $this->reserva)
            <section class="rounded-lg border border-borde bg-tarjeta p-5">
                <h2 class="mb-4 text-lg font-bold">Confirmar Cancelación</h2>
                <dl class="mb-6 divide-y divide-borde text-sm">
                    <div class="flex justify-between py-2"><dt class="text-texto-secundario">Reserva</dt><dd class="font-semibold">{{ $this->reserva->numero_reserva }}</dd></div>
                    <div class="flex justify-between py-2"><dt class="text-texto-secundario">Paquete</dt><dd>{{$this->reserva->excursion->paquete->nombre}}</dd></div>
                    <div class="flex justify-between py-2"><dt class="text-texto-secundario">Salida</dt><dd>{{ $this->reserva->excursion->getFechaSalida()->format('d/m/Y') }}</dd></div>
                    <div class="mt-2 flex justify-between pt-4">
                        <dt class="font-semibold text-texto">Monto a reembolsar</dt>
                        <dd class="font-semibold {{ $montoReembolso > 0 ? 'text-inca' : 'text-texto-secundario' }}">USD {{ number_format($montoReembolso, 0, ',', '.') }}</dd>
                    </div>
                </dl>
                @if($montoReembolso == 0)
                    <div class="mb-6 rounded border-l-4 border-ambar bg-fondo p-3 text-sm text-texto">Faltan {{ $diasLimite }} días o menos. No genera devolución.</div>
                @else
                    <div class="mb-6 rounded border-l-4 border-inca bg-fondo p-3 text-sm text-texto">Te corresponde un reembolso del {{ $porcentajeReembolso }}%.</div>
                @endif
                <div class="mt-4 flex flex-wrap gap-3">
                    <button type="button" wire:click="confirmar" class="rounded border border-peligro bg-tarjeta px-4 py-2 text-sm font-semibold text-peligro hover:bg-peligro hover:text-fondo">Sí, cancelar reserva</button>
                    <button type="button" wire:click="cancelarOperacion" class="rounded border border-borde px-4 py-2 text-sm font-semibold text-texto hover:bg-fondo">No, me arrepentí</button>
                </div>
            </section>
        @endif

        @if ($paso === 3)
            <section class="rounded-lg border border-inca bg-tarjeta p-8 text-center">
                <h2 class="mb-2 text-xl font-bold text-inca">¡Reserva cancelada con éxito!</h2>
                <button type="button" wire:click="cancelarOperacion" class="rounded bg-inca px-6 py-2 font-semibold text-fondo">Volver a mi reserva</button>
            </section>
        @endif
    </div>
</div>