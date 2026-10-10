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
    public string $fechaDestino = '';

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
            // Solo valida: la lista se arma en habilitadas()
            $this->reserva->iniciarModificacion();
            $this->paso = 2;
        } catch (\Exception $e) {
            $this->mensajeError = $e->getMessage();
        }
    }

    public function elegir(string $fecha): void
    {
        $this->mensajeError = '';

        try {
            $this->reserva->elegirExcursionDestino(Carbon::parse($fecha));
            $this->fechaDestino = $fecha;
            $this->paso = 3;
        } catch (\Exception $e) {
            $this->mensajeError = $e->getMessage();
        }
    }

    public function confirmar(): void
    {
        $this->mensajeError = '';

        try {
            // Se vuelve a validar cupo y anticipación: pudieron cambiar mientras miraba el resumen
            $cambio = $this->reserva->elegirExcursionDestino(Carbon::parse($this->fechaDestino));
            $this->reserva->modificarExcursion($cambio['destino']);
            $this->paso = 4;
        } catch (\Exception $e) {
            $this->mensajeError = $e->getMessage();
            $this->paso = 2;
        }
    }

    public function volverALista(): void
    {
        $this->mensajeError = '';
        $this->fechaDestino = '';
        $this->paso = 2;
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

    #[Computed]
    public function habilitadas()
    {
        return $this->reserva->iniciarModificacion();
    }

    #[Computed]
    public function resumen(): array
    {
        return $this->reserva->elegirExcursionDestino(Carbon::parse($this->fechaDestino));
    }
};
?>

<div class="grid gap-6 md:grid-cols-[300px_1fr]">
    {{-- COLUMNA IZQUIERDA: reserva actual --}}
    <section class="self-start rounded-lg border border-borde bg-tarjeta p-5">
        <h1 class="mb-4 font-bold">Cambiar de excursión</h1>

        @if ($this->reserva)
            <dl class="divide-y divide-borde text-sm">
                <div class="flex justify-between py-2"><dt class="text-texto-secundario">Reserva</dt><dd class="font-semibold">{{ $this->reserva->numero_reserva }}</dd></div>
                <div class="flex justify-between py-2"><dt class="text-texto-secundario">Estado</dt><dd>{{ $this->reserva->estado->value }}</dd></div>
                <div class="flex justify-between py-2"><dt class="text-texto-secundario">Paquete</dt><dd>{{ $this->reserva->excursion->paquete->nombre }}</dd></div>
                <div class="flex justify-between py-2"><dt class="text-texto-secundario">Salida actual</dt><dd>{{ $this->reserva->excursion->getFechaSalida()->format('d/m/Y') }}</dd></div>
                <div class="flex justify-between py-2"><dt class="text-texto-secundario">Integrantes</dt><dd>{{ $this->reserva->excursionistas->count() }}</dd></div>
            </dl>
        @endif
    </section>

    {{-- COLUMNA DERECHA: pasos --}}
    <div>
        @if ($mensajeError)
            <div class="mb-4 rounded-lg border border-peligro bg-tarjeta p-5">
                <h2 class="text-lg font-bold text-peligro">No se puede modificar</h2>
                <p class="mt-2 text-sm text-texto">{{ $mensajeError }}</p>
            </div>
        @endif

        {{-- PASO 2: excursiones habilitadas --}}
        @if ($paso === 2)
            <section class="rounded-lg border border-borde bg-tarjeta p-5">
                <h2 class="mb-4 text-lg font-bold">Elegí la nueva salida</h2>

                @forelse ($this->habilitadas as $excursion)
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded border border-borde bg-fondo p-4">
                        <div class="text-sm">
                            <p class="font-semibold">{{ $excursion->fecha_salida->format('d/m/Y') }}</p>
                            <p class="text-texto-secundario">
                                {{ $excursion->obtenerCupoDisponible() }} lugares · Guía {{ $excursion->guia->nombre }} {{ $excursion->guia->apellido }}
                            </p>
                        </div>
                        <button type="button" wire:click="elegir('{{ $excursion->fecha_salida->toDateString() }}')"
                                class="rounded bg-inca px-4 py-2 text-sm font-semibold text-fondo">Elegir</button>
                    </div>
                @empty
                    <div class="mb-4 rounded border-l-4 border-ambar bg-fondo p-3 text-sm text-texto">
                        No hay otras salidas de este paquete con lugar para todo el grupo y con la anticipación mínima.
                    </div>
                @endforelse

                <button type="button" wire:click="cancelarOperacion"
                        class="mt-2 rounded border border-borde px-4 py-2 text-sm font-semibold text-texto hover:bg-fondo">Volver</button>
            </section>
        @endif

        {{-- PASO 3: resumen del cambio --}}
        @if ($paso === 3)
            @php($cambio = $this->resumen)
            <section class="rounded-lg border border-borde bg-tarjeta p-5">
                <h2 class="mb-4 text-lg font-bold">Resumen del cambio</h2>
                <dl class="mb-6 divide-y divide-borde text-sm">
                    <div class="flex justify-between py-2">
                        <dt class="text-texto-secundario">Salida</dt>
                        <dd>{{ $cambio['origen']->fecha_salida->format('d/m/Y') }} → <span class="font-semibold">{{ $cambio['destino']->fecha_salida->format('d/m/Y') }}</span></dd>
                    </div>
                    <div class="flex justify-between py-2">
                        <dt class="text-texto-secundario">Guía</dt>
                        <dd>{{ $cambio['destino']->guia->nombre }} {{ $cambio['destino']->guia->apellido }}</dd>
                    </div>
                </dl>

                <div class="mb-6 rounded border-l-4 border-ambar bg-fondo p-3 text-sm text-texto">
                    La reserva vuelve a «Pendiente» y los permisos de los {{ $this->reserva->excursionistas->count() }} integrantes se validan de nuevo.
                </div>

                <div class="flex flex-wrap gap-3">
                    <button type="button" wire:click="confirmar" class="rounded bg-inca px-4 py-2 text-sm font-semibold text-fondo">Confirmar cambio</button>
                    <button type="button" wire:click="volverALista" class="rounded border border-borde px-4 py-2 text-sm font-semibold text-texto hover:bg-fondo">Volver</button>
                </div>
            </section>
        @endif

        {{-- PASO 4: éxito --}}
        @if ($paso === 4)
            <section class="rounded-lg border border-inca bg-tarjeta p-8 text-center">
                <h2 class="mb-2 text-xl font-bold text-inca">¡Excursión cambiada!</h2>
                <p class="mb-4 text-sm text-texto">La reserva queda pendiente de validación de permisos.</p>
                <button type="button" wire:click="cancelarOperacion" class="rounded bg-inca px-6 py-2 font-semibold text-fondo">Volver a mi reserva</button>
            </section>
        @endif
    </div>
</div>