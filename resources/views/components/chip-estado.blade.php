{{--
    Chip con el estado de una reserva (enum EstadoReserva) o del permiso de un excursionista (enum EstadoPermiso).
    El estado siempre va escrito.
    <x-chip-estado :estado="$reserva->estado" />
    <x-chip-estado :estado="$excursionista->estado_permiso" />
--}}
@use('App\Enums\EstadoPermiso')
@use('App\Enums\EstadoReserva')

@props(['estado'])

@php
    $colores = match ($estado) {
        EstadoReserva::Pendiente, EstadoPermiso::Pendiente => 'bg-ambar-fondo text-ambar',
        EstadoReserva::Confirmada, EstadoPermiso::Obtenido => 'bg-aviso text-enlace',
        EstadoReserva::SinPermiso, EstadoPermiso::NoObtenido => 'bg-error-fondo text-error',
        EstadoReserva::Cancelada => 'bg-gris-fondo text-texto/80',
        EstadoReserva::Finalizada => 'border border-texto text-texto',
    };
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full px-3 py-1 text-sm font-medium whitespace-nowrap '.$colores]) }}>
    {{ $estado->value }}
</span>
