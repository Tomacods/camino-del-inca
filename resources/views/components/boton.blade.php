{{--
    Botón del portal, con forma de pastilla. Con href se dibuja como enlace; sin href, como <button>.
    <x-boton type="submit">Consultar</x-boton>
    <x-boton variante="secundario" href="/paquetes">Ver paquetes</x-boton>
--}}
@props(['variante' => 'primario', 'href' => null, 'type' => 'button'])

@php
    $clases = 'inline-flex min-h-12 items-center justify-center gap-2 rounded-full px-6 py-3 text-center text-[17px] leading-tight '
        .'transition-colors duration-150 ease-out '
        .'disabled:pointer-events-none disabled:border-transparent disabled:bg-gris-fondo disabled:text-texto-secundario '
        .'data-loading:cursor-wait data-loading:opacity-70 ';

    $clases .= match ($variante) {
        'secundario' => 'border border-accion text-enlace hover:bg-accion hover:text-white active:bg-accion-presionado active:text-white',
        default => 'bg-accion text-white hover:bg-accion-hover active:bg-accion-presionado',
    };
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $clases]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $clases]) }}>{{ $slot }}</button>
@endif
