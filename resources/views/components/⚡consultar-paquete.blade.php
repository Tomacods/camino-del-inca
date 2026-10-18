<?php

use App\Models\Paquete;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Paquete')] class extends Component
{
    public int $idPaquete;

    // CU-06, paso 1: el cliente llega desde el listado (CU-05) con la identificación del paquete.
    public function mount(int $idPaquete): void
    {
        $this->idPaquete = $idPaquete;
    }

    // Pasos 2 y 3: el detalle sólo se muestra si el paquete existe y está «Activo». Si no, null (flujo alternativo A1).
    #[Computed]
    public function detalle(): ?array
    {
        $paquete = Paquete::buscarPorId($this->idPaquete);

        return $paquete?->estaActivo() ? $paquete->getDetalles() : null;
    }
};

?>

<div>
    @if ($this->detalle === null)
        {{-- CU-06, flujo alternativo A1: el paquete no existe o ya no está «Activo», por ejemplo desde un enlace guardado. --}}
        <h1 class="text-5xl font-semibold">Paquete.</h1>

        <x-aviso tipo="error" class="mt-10" titulo="Este paquete no está disponible actualmente">
            Puede que se haya dado de baja. Mirá los otros paquetes que tenemos.
        </x-aviso>

        <x-boton href="/paquetes" class="mt-6">Ver los paquetes</x-boton>
    @else
        @php($paquete = $this->detalle)

        <h1 class="text-5xl font-semibold">{{ $paquete['nombre'] }}</h1>

        {{-- El nombre del recorrido ya dice la duración («Camino Inca de 4 días»). --}}
        <p class="mt-3 text-[17px] text-texto-secundario">
            {{ $paquete['nombre_recorrido'] }} · {{ $paquete['cantidad_etapas'] }} etapas
        </p>

        <p class="mt-2 text-[17px]">
            @if ($paquete['promedio_valoraciones'] === null)
                <span class="text-texto-secundario">Todavía no tiene valoraciones.</span>
            @else
                Valoración promedio:
                <span class="font-semibold tabular-nums">{{ number_format($paquete['promedio_valoraciones'], 1, ',', '.') }}</span> de 5
            @endif
        </p>

        <div class="mt-10 grid gap-6 md:grid-cols-2">
            <x-tarjeta titulo="Qué incluye">
                <ul class="space-y-2 text-[17px]">
                    @foreach ($paquete['servicios'] as $servicio)
                        <li>
                            {{ $servicio['nombre'] }}
                            <span class="text-texto-secundario">· {{ $servicio['tipo']->value }}</span>
                        </li>
                    @endforeach
                    <li>{{ $paquete['cantidad_porteadores'] }} porteadores</li>
                </ul>
            </x-tarjeta>

            <x-tarjeta titulo="Precios">
                <dl class="divide-y divide-divisor text-[17px]">
                    <div class="flex justify-between gap-4 pb-3">
                        <dt class="text-texto-secundario">Precio base, por persona</dt>
                        <dd class="font-semibold tabular-nums">USD {{ number_format($paquete['precio_base'], 0, ',', '.') }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 py-3">
                        <dt class="text-texto-secundario">Noche extra en Cusco, por persona</dt>
                        <dd class="tabular-nums">USD {{ number_format($paquete['costo_noche_extra_cusco'], 0, ',', '.') }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 pt-3">
                        <dt class="text-texto-secundario">Equipo de camping (opcional), por equipo</dt>
                        <dd class="tabular-nums">USD {{ number_format($paquete['costo_equipo_camping'], 0, ',', '.') }}</dd>
                    </div>
                </dl>
            </x-tarjeta>
        </div>

        <h2 class="mt-12 text-3xl font-semibold">Salidas disponibles</h2>

        @if (count($paquete['excursiones']) === 0)
            <x-aviso class="mt-6" titulo="Por ahora no hay salidas disponibles">
                Volvé a mirar en unos días.
            </x-aviso>
        @else
            <ul class="mt-6 divide-y divide-divisor rounded-[18px] bg-tarjeta shadow-tarjeta">
                @foreach ($paquete['excursiones'] as $excursion)
                    @php($fecha = $excursion['fecha_salida'])
                    <li class="flex flex-wrap items-center justify-between gap-4 p-5 sm:px-8" wire:key="salida-{{ $fecha->format('Y-m-d') }}">
                        <div>
                            <p class="text-[17px] font-semibold tabular-nums">{{ $fecha->format('d/m/Y') }}</p>
                            <p class="text-sm text-texto-secundario">
                                {{ $excursion['cupo_disponible'] === 1 ? 'Queda 1 lugar' : 'Quedan '.$excursion['cupo_disponible'].' lugares' }}
                                · Guía: {{ $excursion['guia'] }}
                            </p>
                        </div>

                        {{-- CU-06, flujo alternativo A2: reservar esta salida lleva a Realizar Reserva (CU-14). --}}
                        <x-boton variante="secundario" href="/reservar/{{ $paquete['id_paquete'] }}/{{ $fecha->format('Y-m-d') }}">
                            Reservar
                        </x-boton>
                    </li>
                @endforeach
            </ul>
        @endif

        <x-boton variante="secundario" href="/paquetes" class="mt-10">Volver a los paquetes</x-boton>
    @endif
</div>
