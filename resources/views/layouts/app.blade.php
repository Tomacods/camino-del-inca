{{-- Layout del portal del cliente. Lo usan las vistas Blade (<x-layouts::app>) y los componentes Livewire de página completa. --}}
@props(['title' => null])

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' · Camino del Inca' : 'Camino del Inca' }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen flex-col">
    <a href="#contenido"
       class="sr-only rounded-full bg-accion px-5 py-2 text-white focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50">
        Saltar al contenido
    </a>

    {{-- Barra fija y translúcida, como la de la tienda de Apple: el contenido pasa por debajo al bajar. --}}
    <header class="sticky top-0 z-40 border-b border-black/10 bg-tarjeta/80 backdrop-blur-xl backdrop-saturate-150">
        <div class="mx-auto flex h-14 max-w-5xl items-center justify-between gap-6 px-4 sm:px-6">
            <a href="/" class="text-lg font-semibold tracking-tight text-texto">Camino del Inca</a>

            <nav aria-label="Principal" class="-mr-3 flex text-[15px]">
                @foreach (['/paquetes' => 'Paquetes', '/mi-reserva' => 'Mi reserva'] as $ruta => $nombre)
                    @php($actual = request()->is(ltrim($ruta, '/').'*'))
                    <a href="{{ $ruta }}" @if ($actual) aria-current="page" @endif
                       class="rounded-full px-3 py-2 transition-colors {{ $actual ? 'font-medium text-texto' : 'text-texto/70 hover:text-texto' }}">
                        {{ $nombre }}
                    </a>
                @endforeach
            </nav>
        </div>
    </header>

    <main id="contenido" class="mx-auto w-full max-w-5xl flex-1 px-4 pt-12 pb-20 sm:px-6 sm:pt-16">
        {{ $slot }}
    </main>

    <footer class="border-t border-divisor">
        <div class="mx-auto max-w-5xl px-4 py-6 text-sm text-texto-secundario sm:px-6">
            Camino del Inca · Desarrollo de Software, UNPSJB 2026 · Grupo 11
        </div>
    </footer>
</body>
</html>
