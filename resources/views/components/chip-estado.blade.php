{{--
    Chip con el estado de la reserva. Recibe el enum EstadoReserva o su texto; el estado siempre va escrito.
    <x-chip-estado :estado="$reserva->estado" />
--}}
@props(['estado'])

@php
    $texto = $estado instanceof BackedEnum ? $estado->value : $estado;

    $colores = match ($texto) {
        'Pendiente' => 'bg-ambar-fondo text-ambar',
        'Confirmada' => 'bg-aviso text-enlace',
        'Sin Permiso' => 'bg-error-fondo text-error',
        'Cancelada' => 'bg-gris-fondo text-texto/80',
        'Finalizada' => 'border border-texto text-texto',
        default => 'border border-divisor text-texto-secundario',
    };
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full px-3 py-1 text-sm font-medium whitespace-nowrap '.$colores]) }}>
    {{ $texto }}
</span>
