<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Camino del Inca</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-fondo text-gray-100">
    <header class="border-b border-borde">
        <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
            <a href="/" class="border-l-4 border-inca pl-3 text-sm font-bold tracking-widest">CAMINO DEL INCA</a>
            <nav class="flex gap-6 text-sm">
                <a href="/paquetes" class="text-gray-300 hover:text-inca">Paquetes</a>
                <a href="/mi-reserva" class="font-semibold text-inca">Mi reserva</a>
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-5xl px-6 py-8">
        {{ $slot }}
    </main>

    <footer class="py-6 text-center text-xs text-gray-500">
        Camino del Inca · Grupo 11 · Prototipo de interfaz de usuario
    </footer>
</body>
</html>