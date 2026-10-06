<?php

use App\Models\Reserva;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Illuminate\Http\Request;

new class extends Component
{
    public string $correo = '';

    public string $numeroReserva = '';

    public bool $consultado = false;

public function mount(): void
{
    $acceso = session('acceso_reserva');

    if ($acceso) {
        $this->correo = $acceso['correo'];
        $this->numeroReserva = $acceso['numeroReserva'];
        $this->consultado = true;
    }
}

    public function consultar(): void
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

$this->consultado = true;

if ($this->reserva === null) {
    session()->forget('acceso_reserva');
    $this->addError('numeroReserva', 'No encontramos una reserva con esos datos.');
    return;
}

session(['acceso_reserva' => [
    'correo' => $this->correo,
    'numeroReserva' => $this->numeroReserva,
]]);
    }

    #[Computed]
    public function reserva(): ?Reserva
    {
        if (! $this->consultado) {
            return null;
        }

        return Reserva::buscarPorCorreoYNumero($this->correo, $this->numeroReserva);
    }
};

?>

@use('App\Enums\EstadoSaldo')

@php($reserva = $this->reserva)
<div class="mx-auto max-w-xl">
    {{-- 1. Acceso --}}
    <section class="self-start rounded-lg border border-borde bg-tarjeta p-5">
        <h1 class="mb-4 font-bold">Acceder a mi reserva</h1>

        <form wire:submit="consultar" class="space-y-4">
            <div>
                <label for="correo" class="block text-sm text-texto">Correo electrónico</label>
                <input id="correo" type="email" wire:model="correo"
                       class="mt-1 w-full rounded border border-borde bg-fondo px-3 py-2 text-texto">
                @error('correo') <p class="mt-1 text-sm text-peligro">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="numeroReserva" class="block text-sm text-texto">Número de reserva</label>
                <input id="numeroReserva" type="text" wire:model="numeroReserva" placeholder="000124-7"
                       class="mt-1 w-full rounded border border-borde bg-fondo px-3 py-2 text-texto placeholder-texto-secundario">
                @error('numeroReserva') <p class="mt-1 text-sm text-peligro">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="w-full rounded bg-inca py-2 font-semibold text-fondo">Consultar</button>
        </form>
    </section>

    @if ($reserva)
        <div class="space-y-6">
            {{-- 2. Detalle --}}
            <section class="rounded-lg border border-borde bg-tarjeta p-5">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-lg font-bold">Reserva {{ $reserva->numero_reserva }}</h2>
                    <span class="rounded bg-inca px-3 py-1 text-sm font-semibold text-fondo">{{ $reserva->estado->value }}</span>
                </div>

                <dl class="divide-y divide-borde text-sm">
                    <div class="flex justify-between py-2"><dt class="text-texto-secundario">Paquete</dt><dd>{{$reserva->excursion->paquete->nombre}}</dd></div>
                    <div class="flex justify-between py-2"><dt class="text-texto-secundario">Salida</dt><dd>{{ $reserva->excursion->getFechaSalida()->format('d/m/Y') }}</dd></div>
                    <div class="flex justify-between py-2"><dt class="text-texto-secundario">Monto total</dt><dd>USD {{ number_format($reserva->obtenerMontoTotal(), 0, ',', '.') }}</dd></div>
                    <div class="flex justify-between py-2">
                        <dt class="text-texto-secundario">Saldo</dt>
                        <dd class="{{ $reserva->estado_saldo === EstadoSaldo::Adeudado ? 'font-semibold text-ambar' : '' }}">
                            {{ $reserva->estado_saldo->value }} · USD {{ number_format($reserva->obtenerSaldoPendiente(), 0, ',', '.') }}
                        </dd>
                    </div>
                    @foreach ($reserva->pagos as $pago)
                        <div class="flex justify-between py-2">
                            <dt class="text-texto-secundario">Pago</dt>
                            <dd>{{ $pago->fecha->format('d/m/Y') }} · {{ $pago->tipo_pago }} · USD {{ number_format($pago->monto, 0, ',', '.') }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>

            {{-- 3. Opciones --}}
            <section>
                <p class="mb-3 text-sm text-texto">Opciones para una reserva «{{ $reserva->estado->value }}»</p>
                <div class="flex flex-wrap gap-3">
                    @forelse ($reserva->obtenerOpcionesHabilitadas() as $opcion)
                        @if ($opcion === Reserva::OPCION_CANCELAR)
                           <a href="/mi-reserva/{{ $reserva->numero_reserva }}/cancelar"
                               class="rounded border border-peligro px-4 py-2 text-sm font-semibold text-peligro hover:bg-peligro hover:text-fondo">
                                {{ $opcion }}
                            </a>
                        @elseif ($opcion === Reserva::OPCION_REINTEGRO)
                            <a href="/mi-reserva/{{ $reserva->numero_reserva }}/reintegro"
                            class="rounded border border-inca px-4 py-2 text-sm font-semibold text-inca hover:bg-inca hover:text-fondo">
                                {{ $opcion }}
                            </a>
                        @else
                            <button type="button" class="rounded border px-4 py-2 text-sm font-semibold border-divisor text-texto">
                                {{ $opcion }}
                            </button>
                        @endif
                    @empty
                        <p class="text-sm text-texto-secundario">Esta reserva no tiene opciones de gestión.</p>
                    @endforelse
                </div>
            </section>
        </div>
    @endif
</div>