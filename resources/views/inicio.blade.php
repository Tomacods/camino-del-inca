<x-layouts::app>
    <section class="flex flex-col gap-8 lg:flex-row lg:items-end lg:justify-between lg:gap-16">
        <h1 class="max-w-3xl text-5xl leading-[1.05] font-semibold sm:text-6xl lg:text-7xl">
            Camino del Inca.
            <span class="text-texto-secundario">Reservá tu excursión sin crear una cuenta.</span>
        </h1>

        <p class="shrink-0 text-[15px] leading-snug lg:pb-3 lg:text-right">
            <span class="block text-texto-secundario">¿Ya reservaste?</span>
            <a href="/mi-reserva" class="group inline-flex items-center gap-0.5 text-enlace hover:underline">
                Consultá tu reserva
                <svg class="size-3.5 transition-transform duration-150 ease-out group-hover:translate-x-0.5" viewBox="0 0 16 16"
                     fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m6 3.5 4.5 4.5L6 12.5" />
                </svg>
            </a>
        </p>
    </section>

    <div class="mt-10 flex flex-col gap-6 sm:flex-row sm:items-center">
        <x-boton href="/paquetes" class="shrink-0">Ver los paquetes</x-boton>
        <p class="max-w-[52ch] text-[17px] leading-relaxed text-texto-secundario">
            Elegí un paquete y una fecha de salida, reservá para vos y tus acompañantes y pagá con Mercado Pago.
        </p>
    </div>

    <section aria-labelledby="como-reservar" class="mt-24 sm:mt-32">
        <h2 id="como-reservar" class="text-3xl font-semibold sm:text-4xl">
            Cómo se reserva.
            <span class="text-texto-secundario">Tres pasos, desde tu casa.</span>
        </h2>

        <ol class="mt-8 grid gap-5 md:grid-cols-3">
            @foreach ([
                ['Elegí el paquete', 'Mirá qué incluye cada paquete y las fechas de salida con lugares disponibles.'],
                ['Reservá y pagá', 'Cargá los datos de cada excursionista. Tu lugar queda guardado 5 minutos mientras pagás.'],
                ['Seguí tu reserva', 'Te llega un correo con el número de reserva. Con ese número y tu correo ves el estado y pagás el saldo.'],
            ] as $indice => [$titulo, $texto])
                <li class="flex flex-col rounded-[18px] bg-tarjeta p-7 shadow-tarjeta">
                    <span class="flex size-9 items-center justify-center rounded-full bg-aviso font-semibold text-enlace tabular-nums" aria-hidden="true">{{ $indice + 1 }}</span>
                    <h3 class="mt-5 text-2xl font-semibold">{{ $titulo }}</h3>
                    <p class="mt-3 text-[17px] leading-relaxed text-texto-secundario">{{ $texto }}</p>
                </li>
            @endforeach
        </ol>
    </section>
</x-layouts::app>
