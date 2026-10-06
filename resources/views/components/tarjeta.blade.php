{{--
    Tarjeta blanca sobre el fondo gris, con sombra suave. El título es opcional.
    <x-tarjeta titulo="Datos de la reserva">...</x-tarjeta>
--}}
@props(['titulo' => null])

<section {{ $attributes->merge(['class' => 'rounded-[18px] bg-tarjeta p-6 shadow-tarjeta sm:p-8']) }}>
    @if ($titulo)
        <h2 class="mb-4 text-2xl font-semibold text-texto">{{ $titulo }}</h2>
    @endif

    {{ $slot }}
</section>
