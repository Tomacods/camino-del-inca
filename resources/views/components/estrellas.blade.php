{{--
    Un puntaje de 1 a 5 estrellas (Valorar Servicios, CU-23): un grupo de botones de opción con su etiqueta accesible.
    <x-estrellas modelo="experienciaGeneral" etiqueta="El recorrido completo" :valor="$experienciaGeneral" />
    El error sale de la validación con el mismo nombre que el modelo.
--}}
@props(['modelo', 'etiqueta', 'valor' => null])

@php($idGrupo = str_replace('.', '-', $modelo))

<fieldset class="py-4 first:pt-0 last:pb-0" aria-labelledby="{{ $idGrupo }}-etiqueta" @error($modelo) aria-describedby="{{ $idGrupo }}-error" @enderror>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <span id="{{ $idGrupo }}-etiqueta" class="text-[17px] text-texto">{{ $etiqueta }}</span>

        <div class="flex gap-1">
            @foreach (range(1, 5) as $estrellas)
                <label class="cursor-pointer rounded-full p-1 focus-within:shadow-[0_0_0_4px_rgb(0_113_227/0.25)]">
                    <input type="radio" class="sr-only" wire:model.live="{{ $modelo }}" value="{{ $estrellas }}"
                           name="{{ $idGrupo }}" aria-label="{{ $estrellas }} de 5 estrellas">
                    <svg class="size-8 transition-colors duration-150 ease-out {{ $valor !== null && $estrellas <= (int) $valor ? 'text-ambar' : 'text-divisor' }}"
                         viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path d="M10 1.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L10 14.9l-5.2 2.7 1-5.8L1.5 7.7l5.9-.9z" />
                    </svg>
                </label>
            @endforeach
        </div>
    </div>

    @error($modelo)
        <p id="{{ $idGrupo }}-error" class="mt-2 text-sm text-error">{{ $message }}</p>
    @enderror
</fieldset>
