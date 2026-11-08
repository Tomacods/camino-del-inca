<?php

use App\Models\Paquete;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Paquetes')] class extends Component
{
    // Lo que escribe el cliente en el buscador: un nombre, y/o un rango de fechas de salida (dd/mm/aaaa). Desde y Hasta
    // pueden ir solos, o los dos. Si son iguales, es una sola fecha.
    public string $criterio = '';

    public string $desde = '';

    public string $hasta = '';

    // Lo que se aplicó al tocar «Buscar». Sólo lo pone buscarPaquetes(), después de validarlo; con #[Locked] el navegador
    // no lo puede cambiar, así nunca llega a la búsqueda sin validar.
    #[Locked]
    public string $busqueda = '';

    #[Locked]
    public string $busquedaDesde = '';

    #[Locked]
    public string $busquedaHasta = '';

    protected function rules(): array
    {
        return [
            'criterio' => ['max:100'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    protected function messages(): array
    {
        return [
            'criterio.max' => 'La búsqueda puede tener hasta 100 caracteres.',
            'desde.date_format' => 'Elegí una fecha válida en el calendario.',
            'hasta.date_format' => 'Elegí una fecha válida en el calendario.',
        ];
    }

    // CU-05, pasos 3 y 4. Si un formato no es válido (A4), o «Hasta» es anterior a «Desde», el listado queda como estaba.
    public function buscarPaquetes(): void
    {
        $this->criterio = trim($this->criterio);
        $this->desde = trim($this->desde);
        $this->hasta = trim($this->hasta);

        $this->validate();

        // Carbon compara contra «ahora» si le pasan null, así que sólo se compara cuando están las dos fechas.
        if ($this->desde !== '' && $this->hasta !== ''
            && $this->fecha($this->desde)->greaterThan($this->fecha($this->hasta))) {
            $this->addError('hasta', 'La fecha «Hasta» tiene que ser igual o posterior a «Desde».');

            return;
        }

        $this->busqueda = $this->criterio;
        $this->busquedaDesde = $this->desde;
        $this->busquedaHasta = $this->hasta;
    }

    public function verTodos(): void
    {
        $this->reset('criterio', 'desde', 'hasta', 'busqueda', 'busquedaDesde', 'busquedaHasta');
        $this->resetValidation();
    }

    // Sin búsqueda aplicada se ve el listado completo.
    public function hayBusqueda(): bool
    {
        return $this->busqueda !== '' || $this->busquedaDesde !== '' || $this->busquedaHasta !== '';
    }

    // Cómo se nombra la búsqueda en los avisos: «Clásico» con la salida del 15/02/2027, o las salidas entre dos fechas.
    public function descripcionBusqueda(): string
    {
        $fechas = match (true) {
            $this->busquedaDesde !== '' && $this->busquedaDesde === $this->busquedaHasta => 'la salida del '.$this->mostrar($this->busquedaDesde),
            $this->busquedaDesde !== '' && $this->busquedaHasta !== '' => 'las salidas entre el '.$this->mostrar($this->busquedaDesde).' y el '.$this->mostrar($this->busquedaHasta),
            $this->busquedaDesde !== '' => 'las salidas desde el '.$this->mostrar($this->busquedaDesde),
            $this->busquedaHasta !== '' => 'las salidas hasta el '.$this->mostrar($this->busquedaHasta),
            default => '',
        };

        if ($fechas === '') {
            return $this->busqueda !== '' ? '«'.$this->busqueda.'»' : '';
        }

        return $this->busqueda !== '' ? '«'.$this->busqueda.'» con '.$fechas : $fechas;
    }

    // CU-05, paso 2 y paso 4: los paquetes con sus datos resumen, ya calculados para que la vista no haga consultas.
    #[Computed]
    public function paquetes(): array
    {
        $paquetes = $this->hayBusqueda()
            ? Paquete::buscarPorNombreYFechas(
                $this->busqueda,
                $this->fecha($this->busquedaDesde),
                $this->fecha($this->busquedaHasta),
            )
            : Paquete::listarPaquetes();

        // Las valoraciones de todos los paquetes en una sola consulta; un paquete sin valoraciones queda en null.
        $valoraciones = Paquete::resumenDeValoraciones();

        return $paquetes
            ->map(fn (Paquete $paquete) => [
                ...$paquete->getDatosResumen(),
                'valoraciones' => $valoraciones->get($paquete->id_paquete),
            ])
            ->all();
    }

    // Una fecha ya validada en formato dd/mm/aaaa, o null si el campo está vacío.
    private function fecha(string $texto): ?Carbon
    {
        return $texto === '' ? null : Carbon::createFromFormat('Y-m-d', $texto)->startOfDay();
    }

    // Para mostrar en los avisos: la fecha en el formato que usa el cliente, dd/mm/aaaa.
    private function mostrar(string $fecha): string
    {
        return Carbon::createFromFormat('Y-m-d', $fecha)->format('d/m/Y');
    }
};

?>

<div>
    <h1 class="text-5xl font-semibold">
        Paquetes. <span class="text-texto-secundario">Elegí el que más te guste.</span>
    </h1>

    @if (session('paquete_no_disponible'))
        {{-- CU-06, flujo alternativo A1: el paquete que se quiso ver no existe o ya no está «Activo». --}}
        <x-aviso tipo="error" class="mt-10" titulo="Ese paquete no está disponible actualmente">
            Puede que se haya dado de baja. Elegí otro de la lista.
        </x-aviso>
    @endif

    {{-- CU-05, paso 3: el criterio es opcional; sin criterio ni fechas se ve el listado completo. --}}
    <x-tarjeta class="mt-10">
        <form wire:submit="buscarPaquetes" class="grid gap-5 md:grid-cols-[2fr_1fr_1fr_auto]">
            <x-campo nombre="criterio" etiqueta="Nombre del paquete" wire:model="criterio" placeholder="Ej.: Clásico" />
            {{-- El navegador muestra el calendario para elegir la fecha; no hace falta escribirla. --}}
            <x-campo nombre="desde" type="date" etiqueta="Salidas desde" wire:model="desde" />
            <x-campo nombre="hasta" type="date" etiqueta="Hasta" wire:model="hasta" />

            <div>
                {{-- Ocupa el lugar de la etiqueta, para que el botón quede a la altura de los campos. --}}
                <span class="hidden text-[15px] font-medium md:block" aria-hidden="true">&nbsp;</span>
                <x-boton type="submit" class="min-h-14 w-full md:mt-2">Buscar</x-boton>
            </div>
        </form>
    </x-tarjeta>

    @if ($this->hayBusqueda() && count($this->paquetes) > 0)
        <p class="mt-8 text-[17px] text-texto-secundario">
            Resultados para {{ $this->descripcionBusqueda() }}.
            <button type="button" wire:click="verTodos" class="text-enlace hover:underline">Ver todos los paquetes</button>
        </p>
    @endif

    @if (count($this->paquetes) === 0 && $this->hayBusqueda())
        {{-- CU-05, flujo alternativo A1: la búsqueda no tiene coincidencias. --}}
        <x-aviso class="mt-10" titulo="No encontramos paquetes para {{ $this->descripcionBusqueda() }}">
            Probá con otro nombre o con otras fechas de salida.
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
                    {{-- El nombre del recorrido ya dice la duración («Camino Inca de 4 días»). --}}
                    <p class="text-[17px] text-texto-secundario">{{ $paquete['nombre_recorrido'] }}</p>

                    <p class="mt-4 text-[17px]">
                        Desde <span class="font-semibold tabular-nums">USD {{ number_format((float) $paquete['precio_base'], 2, ',', '.') }}</span>
                        por persona
                    </p>

                    {{-- Reseñas públicas: estrellas del promedio de la experiencia general y acceso a los comentarios. --}}
                    @php($valoracion = $paquete['valoraciones'])
                    @if ($valoracion)
                        @php($llenas = (int) round((float) $valoracion->promedio))
                        <div class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-2xl bg-fondo px-4 py-3">
                            <span role="img" aria-label="Promedio: {{ number_format((float) $valoracion->promedio, 1, ',', '.') }} de 5 estrellas"
                                  class="text-xl leading-none tracking-wider">
                                <span class="text-ambar">{{ str_repeat('★', $llenas) }}</span><span class="text-divisor">{{ str_repeat('★', 5 - $llenas) }}</span>
                            </span>
                            <span class="text-[15px]">
                                <span class="font-semibold tabular-nums">{{ number_format((float) $valoracion->promedio, 1, ',', '.') }}</span>
                                de 5 · {{ $valoracion->cantidad }} {{ $valoracion->cantidad === 1 ? 'valoración' : 'valoraciones' }}
                            </span>
                            <a href="/paquetes/{{ $paquete['id_paquete'] }}/comentarios"
                               class="ml-auto text-[15px] font-medium text-enlace hover:underline">Ver comentarios →</a>
                        </div>
                    @else
                        <p class="mt-4 text-[15px] text-texto-secundario">Todavía sin valoraciones</p>
                    @endif

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
