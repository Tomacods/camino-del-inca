<?php

use App\Models\Excursion;
use App\Models\Paquete;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Paquetes')] class extends Component
{
    // Cuántas casillas de fecha muestra como máximo cada tarjeta. Si el paquete tiene más salidas, la última casilla
    // lleva a su ficha (CU-06), donde están todas: así un paquete que sale cada semana no alarga la tarjeta.
    private const CASILLAS_POR_TARJETA = 6;

    // Lo que escribe el cliente en el buscador.
    public string $criterio = '';

    // El criterio que se aplicó al tocar «Buscar». Vacío: el listado completo. Lo pone sólo buscarPaquetes(), después de
    // validarlo; con #[Locked] el navegador no lo puede cambiar, así nunca llega a la búsqueda sin validar.
    #[Locked]
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
            'criterio.date_format' => 'La fecha de salida tiene que tener el formato dd/mm/aaaa, por ejemplo 15/03/2027.',
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
            ->map(fn (Paquete $paquete) => $this->conSalidasVisibles($paquete->getDatosResumen()))
            ->all();
    }

    // Agrega al resumen las salidas que entran en la tarjeta y cuántas quedan para la ficha del paquete. Si se buscó
    // por fecha, la salida buscada siempre se ve, aunque no sea de las próximas.
    private function conSalidasVisibles(array $resumen): array
    {
        $excursiones = $resumen['excursiones'];
        $visibles = $excursiones;

        if ($excursiones->count() > self::CASILLAS_POR_TARJETA) {
            // Una casilla queda para «N fechas más».
            $visibles = $excursiones->take(self::CASILLAS_POR_TARJETA - 1);
            $buscada = $excursiones->first(fn (Excursion $excursion) => $this->esLaBuscada($excursion));

            if ($buscada !== null && ! $visibles->contains($buscada)) {
                $visibles = $visibles->take(self::CASILLAS_POR_TARJETA - 2)->push($buscada);
            }
        }

        return [
            ...$resumen,
            'salidas_visibles' => $visibles,
            'salidas_ocultas' => $excursiones->count() - $visibles->count(),
        ];
    }

    // El criterio ya pasó la validación con el formato dd/mm/aaaa, así que alcanza con comparar el texto. La vista
    // hace la misma comparación para resaltar la casilla.
    private function esLaBuscada(Excursion $excursion): bool
    {
        return $excursion->getFechaSalida()->format('d/m/Y') === $this->busqueda;
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
    {{-- Portada: la llegada a Machu Picchu. Es una foto propia del grupo (la de la tía de Tomás), así que no lleva
         créditos; la misma se usa al final de los hitos de la portada (ver docs/guia-visual-portal.md). --}}
    <div class="relative h-80 overflow-hidden rounded-[28px] bg-texto sm:h-[26rem]">
        <img src="{{ asset('img/recorrido/llegada-horizontal.webp') }}" alt="" width="1280" height="720" fetchpriority="high"
             class="absolute inset-0 size-full object-cover object-[50%_35%]">

        {{-- Degradé oscuro desde abajo, para que el título blanco se lea sobre cualquier parte de la foto. --}}
        <div class="absolute inset-0 bg-linear-to-t from-black/85 via-black/50 to-black/5" aria-hidden="true"></div>

        <p class="absolute top-4 right-4 rounded-xl bg-texto/70 px-3 py-1 text-sm text-white sm:right-6">Machu Picchu</p>

        {{-- bottom-20 deja lugar para el buscador, que se monta sobre el borde de la foto. --}}
        <h1 class="absolute inset-x-6 bottom-20 text-4xl font-semibold text-balance text-white sm:inset-x-10 sm:bottom-24 sm:text-5xl">
            Paquetes. <span class="text-white/80">Elegí el que más te guste.</span>
        </h1>
    </div>

    {{-- CU-05, paso 3: el criterio es opcional; sin criterio se ve el listado completo. --}}
    <x-tarjeta class="relative mx-3 -mt-14 sm:mx-8">
        <form wire:submit="buscarPaquetes" class="grid gap-5 md:grid-cols-[1fr_auto]">
            <x-campo nombre="criterio" etiqueta="Buscar por nombre o fecha de salida" wire:model="criterio"
                     placeholder="Ej.: Clásico o 15/03/2027" />

            <div>
                {{-- Ocupa el lugar de la etiqueta, para que el botón quede a la altura del campo. --}}
                <span class="hidden text-[15px] font-medium md:block" aria-hidden="true">&nbsp;</span>
                <x-boton type="submit" class="min-h-14 w-full md:mt-2">Buscar</x-boton>
            </div>
        </form>
    </x-tarjeta>

    @if (session('paquete_no_disponible'))
        {{-- CU-06, flujo alternativo A1: el paquete que se quiso ver no existe o ya no está «Activo». --}}
        <x-aviso tipo="error" class="mt-10" titulo="Ese paquete no está disponible actualmente">
            Puede que se haya dado de baja. Elegí otro de la lista.
        </x-aviso>
    @endif

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
                    {{-- El nombre del recorrido ya dice la duración («Camino Inca de 4 días»). --}}
                    <p class="text-[17px] text-texto-secundario">{{ $paquete['nombre_recorrido'] }}</p>

                    {{-- El precio es lo que más se compara entre paquetes: va grande, con «desde» y «por persona» chicos. --}}
                    <p class="mt-5 text-sm text-texto-secundario">
                        Desde
                        <span class="block text-3xl font-semibold tracking-tight text-texto tabular-nums">USD {{ number_format((float) $paquete['precio_base'], 2, ',', '.') }}</span>
                        por persona
                    </p>

                    <h3 class="mt-6 border-t border-divisor pt-5 text-sm font-semibold text-texto-secundario">Salidas disponibles</h3>

                    @if ($paquete['excursiones']->isEmpty())
                        <p class="mt-2 text-sm text-texto-secundario">Sin salidas disponibles por ahora.</p>
                    @else
                        <p class="mt-1 text-sm text-texto-secundario">Elegí una fecha para reservar.</p>

                        {{-- CU-05, flujo alternativo A3: reservar directamente una salida lleva a Realizar Reserva (CU-14). --}}
                        {{-- Dos columnas parejas; como mucho tres filas (ver CASILLAS_POR_TARJETA). --}}
                        <ul class="mt-3 grid grid-cols-2 gap-2">
                            @foreach ($paquete['salidas_visibles'] as $excursion)
                                @php($fecha = $excursion->getFechaSalida())
                                @php($buscada = $fecha->format('d/m/Y') === $busqueda)
                                <li>
                                    <a href="/reservar/{{ $paquete['id_paquete'] }}/{{ $fecha->format('Y-m-d') }}"
                                       aria-label="Reservar la salida del {{ $fecha->format('d/m/Y') }}"
                                       @class([
                                           'flex min-h-12 items-center justify-center rounded-full border px-3 text-sm tabular-nums',
                                           'text-enlace transition-colors duration-150 ease-out hover:border-accion hover:bg-aviso',
                                           'border-accion bg-aviso font-semibold' => $buscada,
                                           'border-divisor' => ! $buscada,
                                       ])>
                                        {{ $fecha->format('d/m/Y') }}
                                    </a>
                                </li>
                            @endforeach

                            @if ($paquete['salidas_ocultas'] > 0)
                                <li>
                                    <a href="/paquetes/{{ $paquete['id_paquete'] }}#salidas"
                                       aria-label="Ver las otras {{ $paquete['salidas_ocultas'] }} fechas de salida de {{ $paquete['nombre'] }}"
                                       class="flex min-h-12 items-center justify-center rounded-full px-3 text-sm font-medium text-enlace
                                              transition-colors duration-150 ease-out hover:bg-aviso">
                                        {{ $paquete['salidas_ocultas'] }} fechas más
                                    </a>
                                </li>
                            @endif
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
