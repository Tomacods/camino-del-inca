{{--
    Aviso informativo o de error. El título es opcional; el texto va en el contenido.
    <x-aviso titulo="Tu lugar está reservado">Tenés 5 minutos para completar el pago.</x-aviso>
    <x-aviso tipo="error">No pudimos registrar el pago. Probá de nuevo.</x-aviso>
--}}
@props(['tipo' => 'info', 'titulo' => null])

@php($esError = $tipo === 'error')

<div role="{{ $esError ? 'alert' : 'status' }}"
     {{ $attributes->merge(['class' => 'flex gap-3 rounded-2xl p-4 sm:p-5 '.($esError ? 'bg-error-fondo' : 'bg-aviso')]) }}>
    <svg class="mt-0.5 size-5 shrink-0 {{ $esError ? 'text-error' : 'text-accion' }}" viewBox="0 0 20 20" fill="none"
         stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
        <circle cx="10" cy="10" r="7.75" />
        @if ($esError)
            <path d="M10 6v5M10 13.5v.5" />
        @else
            <path d="M10 9v5M10 6v.5" />
        @endif
    </svg>

    <div class="text-[15px] leading-relaxed text-texto">
        @if ($titulo)
            <p class="font-semibold">{{ $titulo }}</p>
        @endif
        <div>{{ $slot }}</div>
    </div>
</div>
