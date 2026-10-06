{{--
    Campo de texto con etiqueta. El error sale solo de la validación (@error) con el mismo nombre del campo.
    <x-campo nombre="correo" etiqueta="Correo electrónico" type="email" wire:model="correo" />
    Con :valido="true" el borde pasa a verde, para marcar un dato ya comprobado.
--}}
@props(['nombre', 'etiqueta', 'type' => 'text', 'ayuda' => null, 'valido' => false])

@php
    $hayError = $errors->has($nombre);

    $estado = match (true) {
        $hayError => 'border-error bg-error-fondo',
        $valido => 'border-exito bg-tarjeta',
        default => 'border-borde-campo bg-tarjeta',
    };

    $descripcion = collect([
        $ayuda ? $nombre.'-ayuda' : null,
        $hayError ? $nombre.'-error' : null,
    ])->filter()->implode(' ');
@endphp

<div {{ $attributes->only('class') }}>
    <label for="{{ $nombre }}" class="block text-[15px] font-medium text-texto">{{ $etiqueta }}</label>

    @if ($ayuda)
        <p id="{{ $nombre }}-ayuda" class="mt-1 text-sm text-texto-secundario">{{ $ayuda }}</p>
    @endif

    <div class="relative mt-2">
        <input id="{{ $nombre }}" name="{{ $nombre }}" type="{{ $type }}"
               @if ($hayError) aria-invalid="true" @endif
               @if ($descripcion) aria-describedby="{{ $descripcion }}" @endif
               {{ $attributes->except('class')->merge([
                   'class' => 'block min-h-14 w-full rounded-xl border px-4 py-3 text-[17px] text-texto '
                       .'placeholder:text-texto-secundario transition-[border-color,box-shadow] duration-150 ease-out '
                       .'focus:border-accion focus:shadow-[0_0_0_4px_rgb(0_113_227/0.25)] focus:outline-none '
                       .($valido && ! $hayError ? 'pr-12 ' : '').$estado,
               ]) }}>

        @if ($valido && ! $hayError)
            <svg class="pointer-events-none absolute top-1/2 right-4 size-5 -translate-y-1/2 text-exito" viewBox="0 0 20 20"
                 fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="m5 10.5 3.25 3.25L15 7" />
            </svg>
        @endif
    </div>

    @error($nombre)
        <p id="{{ $nombre }}-error" class="mt-2 flex items-start gap-1.5 text-sm text-error">
            <svg class="mt-0.5 size-4 shrink-0" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <circle cx="8" cy="8" r="6.25" />
                <path d="M8 4.75v3.75M8 10.75v.5" stroke-linecap="round" />
            </svg>
            {{ $message }}
        </p>
    @enderror
</div>
