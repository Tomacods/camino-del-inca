<?php

use App\Models\Paquete;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Paquetes')] class extends Component
{
    // CU-05, paso 2: los paquetes activos con sus datos resumen, ya calculados para que la vista no haga consultas.
    #[Computed]
    public function paquetes(): array
    {
        return Paquete::listarPaquetes()
            ->map(fn (Paquete $paquete) => $paquete->getDatosResumen())
            ->all();
    }
};

?>

<div>
    <h1 class="text-5xl font-semibold">
        Paquetes. <span class="text-texto-secundario">Elegí el que más te guste.</span>
    </h1>

    @if (count($this->paquetes) === 0)
        <x-aviso class="mt-10" titulo="Por ahora no hay paquetes disponibles">
            Volvé a mirar en unos días.
        </x-aviso>
    @else
        <div class="mt-10 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->paquetes as $paquete)
                <x-tarjeta :titulo="$paquete['nombre']" class="flex flex-col" wire:key="paquete-{{ $paquete['id_paquete'] }}">
                    <p class="text-[17px] text-texto-secundario">
                        {{ $paquete['nombre_recorrido'] }} · {{ $paquete['duracion_dias'] }} días
                    </p>

                    <p class="mt-4 text-[17px]">
                        Desde <span class="font-semibold tabular-nums">USD {{ number_format($paquete['precio_base'], 0, ',', '.') }}</span>
                        por persona
                    </p>

                    <h3 class="mt-6 text-sm font-semibold text-texto-secundario">Salidas disponibles</h3>

                    @if ($paquete['excursiones']->isEmpty())
                        <p class="mt-2 text-sm text-texto-secundario">Sin salidas disponibles por ahora.</p>
                    @else
                        <p class="mt-1 text-sm text-texto-secundario">Elegí una fecha para reservar.</p>

                        {{-- CU-05, flujo alternativo A3: reservar directamente una salida lleva a Realizar Reserva (CU-14). --}}
                        <ul class="mt-3 flex flex-wrap gap-2">
                            @foreach ($paquete['excursiones'] as $excursion)
                                @php($fecha = $excursion->getFechaSalida())
                                <li>
                                    <a href="/reservar/{{ $paquete['id_paquete'] }}/{{ $fecha->format('Y-m-d') }}"
                                       aria-label="Reservar la salida del {{ $fecha->format('d/m/Y') }}"
                                       class="inline-flex min-h-12 items-center rounded-full border border-divisor px-4 text-sm tabular-nums
                                              text-enlace transition-colors duration-150 ease-out hover:border-accion hover:bg-aviso">
                                        {{ $fecha->format('d/m/Y') }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    {{-- El detalle es el CU-06, que todavía no tiene pantalla. --}}
                    <div class="mt-auto pt-6">
                        <x-boton variante="secundario" disabled class="w-full flex-col">
                            Ver el paquete
                            <span class="text-sm">Disponible próximamente</span>
                        </x-boton>
                    </div>
                </x-tarjeta>
            @endforeach
        </div>
    @endif
</div>
