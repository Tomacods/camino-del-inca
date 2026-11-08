<?php

use App\Models\Paquete;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

// Reseñas públicas de un paquete: los comentarios de los clientes, del más nuevo al más viejo, con filtro por puntaje.
new #[Title('Comentarios del paquete')] class extends Component
{
    #[Locked]
    public int $idPaquete;

    // El filtro va en la dirección (?puntaje=4): se puede compartir y volver con el botón atrás.
    #[Url(as: 'puntaje')]
    public ?int $puntaje = null;

    public function mount(int $idPaquete): void
    {
        $this->idPaquete = $idPaquete;

        // Un valor fuera de 1 a 5 se ignora y se muestran todos.
        if ($this->puntaje !== null && ! in_array($this->puntaje, [1, 2, 3, 4, 5], true)) {
            $this->puntaje = null;
        }

        // Como el detalle del CU-06: un paquete que no está activo no tiene página pública.
        if (! Paquete::buscarPorId($idPaquete)?->estaActivo()) {
            session()->flash('paquete_no_disponible', true);

            $this->redirect('/paquetes');
        }
    }

    public function filtrar(?int $puntaje): void
    {
        $this->puntaje = $puntaje;
    }

    #[Computed]
    public function paquete(): ?Paquete
    {
        return Paquete::buscarPorId($this->idPaquete);
    }

    #[Computed]
    public function promedio(): ?object
    {
        return Paquete::resumenDeValoraciones()->get($this->idPaquete);
    }

    #[Computed]
    public function comentarios(): array
    {
        return $this->paquete?->comentarios($this->puntaje)->all() ?? [];
    }
};

?>

<div class="mx-auto max-w-3xl">
    <a href="/paquetes" class="text-enlace hover:underline">← Volver a los paquetes</a>

    <h1 class="mt-6 text-4xl font-semibold sm:text-5xl">
        Comentarios.
        <span class="text-texto-secundario">{{ $this->paquete?->nombre }}</span>
    </h1>

    @if ($this->promedio)
        @php($llenas = (int) round((float) $this->promedio->promedio))
        <div class="mt-4 inline-flex flex-wrap items-center gap-x-3 gap-y-2 rounded-2xl bg-fondo px-4 py-3">
            <span role="img" aria-label="Promedio: {{ number_format((float) $this->promedio->promedio, 1, ',', '.') }} de 5 estrellas"
                  class="text-xl leading-none tracking-wider">
                <span class="text-ambar">{{ str_repeat('★', $llenas) }}</span><span class="text-divisor">{{ str_repeat('★', 5 - $llenas) }}</span>
            </span>
            <span class="text-[15px]">
                <span class="font-semibold tabular-nums">{{ number_format((float) $this->promedio->promedio, 1, ',', '.') }}</span>
                de 5 · {{ $this->promedio->cantidad }} {{ $this->promedio->cantidad === 1 ? 'valoración' : 'valoraciones' }}
            </span>
        </div>
    @endif

    {{-- Filtro por puntaje de la experiencia general. El botón marcado es el que está activo. --}}
    <div role="group" aria-label="Filtrar por puntaje" class="mt-8 flex flex-wrap gap-2">
        @foreach ([null, 5, 4, 3, 2, 1] as $opcion)
            @php($etiqueta = $opcion === null ? 'Todas' : $opcion.($opcion === 1 ? ' estrella' : ' estrellas'))
            <button type="button" wire:click="filtrar({{ $opcion ?? 'null' }})"
                    aria-pressed="{{ $this->puntaje === $opcion ? 'true' : 'false' }}"
                    class="min-h-11 rounded-full border px-4 text-[15px] transition-colors duration-150 ease-out
                           {{ $this->puntaje === $opcion ? 'border-accion bg-aviso text-texto' : 'border-divisor bg-tarjeta text-texto hover:border-accion' }}">
                {{ $etiqueta }}
            </button>
        @endforeach
    </div>

    <div class="mt-8 grid gap-4">
        @forelse ($this->comentarios as $valoracion)
            @php($estrellas = (int) $valoracion->puntaje_experiencia_general)
            <article class="rounded-[18px] bg-tarjeta p-6 shadow-tarjeta">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span role="img" aria-label="{{ $estrellas }} de 5 estrellas" class="text-xl leading-none tracking-wider">
                        <span class="text-ambar">{{ str_repeat('★', $estrellas) }}</span><span class="text-divisor">{{ str_repeat('★', 5 - $estrellas) }}</span>
                    </span>
                    <time datetime="{{ $valoracion->fecha->format('Y-m-d') }}" class="text-[14px] text-texto-secundario">
                        {{ $valoracion->fecha->format('d/m/Y') }}
                    </time>
                </div>
                <p class="mt-4 text-[17px] leading-relaxed">{{ $valoracion->comentario }}</p>
            </article>
        @empty
            <x-aviso class="mt-4" titulo="Todavía no hay comentarios">
                @if ($this->puntaje !== null)
                    No hay comentarios con {{ $this->puntaje }} estrellas. Probá con otro puntaje.
                @else
                    Cuando los clientes dejen un comentario al valorar su viaje, va a aparecer acá.
                @endif
            </x-aviso>
        @endforelse
    </div>
</div>
