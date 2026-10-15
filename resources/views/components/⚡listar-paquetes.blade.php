<?php

use App\Models\Paquete;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Paquetes')] class extends Component
{
    // Lo que escribe el cliente en el buscador.
    public string $criterio = '';

    // El criterio que se aplicó al tocar «Buscar». Vacío: el listado completo.
    public string $busqueda = '';

    protected function rules(): array
    {
        return [
            'criterio' => [
                'max:100',
                // Escrito sólo con números y separadores, el criterio es una fecha de salida.
                Rule::when($this->esFecha($this->criterio), 'date_format:d/m/Y'),
            ],
        ];
    }

    protected function messages(): array
    {
        return [
            'criterio.max' => 'La búsqueda puede tener hasta 100 caracteres.',
            'criterio.date_format' => 'La fecha de salida tiene que tener el formato dd/mm/aaaa, por ejemplo 04/01/2027.',
        ];
    }

    // CU-05, pasos 3 y 4. Si el formato no es válido (flujo alternativo A4), el listado queda como estaba.
    public function buscarPaquetes(): void
    {
        $this->criterio = trim($this->criterio);
        $this->validate();

        $this->busqueda = $this->criterio;
    }

    public function verTodos(): void
    {
        $this->reset('criterio', 'busqueda');
        $this->resetValidation();
    }

    // CU-05, paso 2 y paso 4: los paquetes con sus datos resumen, ya calculados para que la vista no haga consultas.
    #[Computed]
    public function paquetes(): array
    {
        $paquetes = $this->busqueda === ''
            ? Paquete::listarPaquetes()
            : Paquete::buscarPaquetes($this->criterioDeBusqueda());

        return $paquetes
            ->map(fn (Paquete $paquete) => $paquete->getDatosResumen())
            ->all();
    }

    // El modelo recibe el criterio ya interpretado: una fecha de salida o un texto para buscar en el nombre.
    private function criterioDeBusqueda(): Carbon|string
    {
        return $this->esFecha($this->busqueda)
            ? Carbon::createFromFormat('d/m/Y', $this->busqueda)->startOfDay()
            : $this->busqueda;
    }

    private function esFecha(string $texto): bool
    {
        return preg_match('/^[\d\/.\-\s]+$/', $texto) === 1 && preg_match('/\d/', $texto) === 1;
    }
};

?>

<div>
    <h1 class="text-5xl font-semibold">
        Paquetes. <span class="text-texto-secundario">Elegí el que más te guste.</span>
    </h1>

    {{-- CU-05, paso 3: el criterio es opcional; sin criterio se ve el listado completo. --}}
    <x-tarjeta class="mt-10">
        <form wire:submit="buscarPaquetes" class="grid gap-5 md:grid-cols-[1fr_auto]">
            <x-campo nombre="criterio" etiqueta="Buscar por nombre o fecha de salida" wire:model="criterio"
                     placeholder="Ej.: Clásico o 04/01/2027" />

            <div>
                {{-- Ocupa el lugar de la etiqueta, para que el botón quede a la altura del campo. --}}
                <span class="hidden text-[15px] font-medium md:block" aria-hidden="true">&nbsp;</span>
                <x-boton type="submit" class="min-h-14 w-full md:mt-2">Buscar</x-boton>
            </div>
        </form>
    </x-tarjeta>

    @if ($busqueda !== '' && count($this->paquetes) > 0)
        <p class="mt-8 text-[17px] text-texto-secundario">
            Resultados para «{{ $busqueda }}».
            <button type="button" wire:click="verTodos" class="text-enlace hover:underline">Ver todos los paquetes</button>
        </p>
    @endif

    @if (count($this->paquetes) === 0 && $busqueda !== '')
        {{-- CU-05, flujo alternativo A1: la búsqueda no tiene coincidencias. --}}
        <x-aviso class="mt-10" titulo="No encontramos paquetes para «{{ $busqueda }}»">
            Probá con otro nombre u otra fecha de salida.
            <button type="button" wire:click="verTodos" class="text-enlace hover:underline">Ver todos los paquetes</button>
        </x-aviso>
    @elseif (count($this->paquetes) === 0)
        <x-aviso class="mt-10" titulo="Por ahora no hay paquetes disponibles">
            Volvé a mirar en unos días.
        </x-aviso>
    @else
        <div class="mt-8 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
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

                    {{-- CU-05, flujo alternativo A2: seleccionar el paquete lleva a Consultar Paquete (CU-06). --}}
                    <div class="mt-auto pt-6">
                        <x-boton variante="secundario" href="/paquetes/{{ $paquete['id_paquete'] }}" class="w-full">
                            Ver el paquete
                        </x-boton>
                    </div>
                </x-tarjeta>
            @endforeach
        </div>
    @endif
</div>
