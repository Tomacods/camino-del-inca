{{--
    Línea de tiempo con los cinco hitos del Camino Inca de 4 días. Cada hito se abre para leer cómo aclimatarse y qué
    tan exigente es el tramo; al final, el botón para elegir la fecha de salida.
    <x-hitos-del-camino class="mt-24" />
    <x-hitos-del-camino href="#fechas" />   (en la pantalla de un paquete, para ir directo a sus fechas)
--}}
@props(['href' => '/paquetes'])

@php
    // Los nombres son los de las etapas del «Camino Inca de 4 días» que carga RecorridoSeeder. La altitud, la distancia
    // y las recomendaciones no están en la tabla ETAPA (sólo tiene nombre y orden): guardarlas ahí sería un cambio de
    // esquema, así que por ahora son texto de la portada. Altitudes y distancias aproximadas.
    // Las fotos todavía no existen (PRODUCT.md): cuando estén, van en public/img/recorrido y la ruta se escribe en 'foto'.
    $hitos = [
        [
            'nombre' => 'Kilómetro 82 (Piscacucho)',
            'dia' => 1,
            'altitud' => 2720,
            'kilometro' => 0,
            'resumen' => 'El punto de partida. Se controlan los permisos y los pasaportes, y se cruza el río Urubamba.',
            'dificultad' => 1,
            'detalleDificultad' => 'Un arranque tranquilo: sendero casi llano junto al río, con subidas cortas.',
            'aclimatacion' => [
                'Llegá a Cusco (3.400 msnm) al menos dos días antes de la salida. Al reservar podés sumar noches extra en Cusco.',
                'Tomá agua seguido, aunque no tengas sed.',
                'Caminá a tu ritmo desde el primer paso, sin apurarte.',
            ],
            'foto' => null,
        ],
        [
            'nombre' => 'Wayllabamba',
            'dia' => 1,
            'altitud' => 3000,
            'kilometro' => 12,
            'resumen' => 'El último pueblo antes de la montaña. Acá termina el primer día de caminata.',
            'dificultad' => 2,
            'detalleDificultad' => 'Unos 12 km en el día, de 5 a 6 horas, con una subida sostenida al final.',
            'aclimatacion' => [
                'Cená liviano y evitá el alcohol: el cuerpo todavía se está adaptando a la altura.',
                'Un dolor de cabeza leve es común las primeras noches. Si es fuerte o viene con mareos o náuseas, avisale al guía.',
                'Dormí abrigado: de noche la temperatura baja mucho.',
            ],
            'foto' => null,
        ],
        [
            'nombre' => 'Abra Warmiwañusca',
            'dia' => 2,
            'altitud' => 4215,
            'kilometro' => 20,
            'resumen' => 'El punto más alto del recorrido. En quechua, su nombre significa «paso de la mujer muerta».',
            'dificultad' => 4,
            'detalleDificultad' => 'La subida más dura: más de 1.200 m de desnivel por escalones de piedra altos, en 4 a 5 horas. '
                .'Después, una bajada empinada hasta el campamento de Pacaymayo (3.600 msnm).',
            'aclimatacion' => [
                'Subí despacio, con pasos cortos y paradas breves. No es una carrera.',
                'Tené agua y algo dulce a mano, para no frenar a buscarlos.',
                'Arriba hace frío y hay viento: abrigate antes de parar.',
                'Si los síntomas de la altura empeoran mientras subís, decíselo al guía: bajar es lo que más los alivia.',
            ],
            'foto' => null,
        ],
        [
            'nombre' => 'Wiñay Wayna',
            'dia' => 3,
            'altitud' => 2650,
            'kilometro' => 38,
            'resumen' => 'Terrazas incas cuyo nombre en quechua significa «siempre joven». Acá se pasa la última noche.',
            'dificultad' => 3,
            'detalleDificultad' => 'El día más largo: unos 16 km, con un segundo paso a 3.950 msnm y un descenso largo por '
                .'escalones de piedra.',
            'aclimatacion' => [
                'Bajando, la altura ya no es el problema: cuidá las rodillas. Los bastones de trekking ayudan mucho.',
                'Acostate temprano: el último día se sale de madrugada.',
            ],
            'foto' => null,
        ],
        [
            'nombre' => 'Machu Picchu',
            'dia' => 4,
            'altitud' => 2430,
            'kilometro' => 43,
            'resumen' => 'La llegada. Se entra por Inti Punku, la Puerta del Sol, con la ciudadela a la vista.',
            'dificultad' => 1,
            'detalleDificultad' => 'Unos 5 km. El único esfuerzo es un tramo corto de escalones muy empinados antes de Inti Punku.',
            'aclimatacion' => [
                'Es el punto más bajo del recorrido: a esta altura el cuerpo ya está adaptado.',
                'Llevá protector solar, sombrero y agua para recorrer la ciudadela.',
            ],
            'foto' => null,
        ],
    ];

    $nivelesDificultad = [1 => 'Baja', 2 => 'Moderada', 3 => 'Alta', 4 => 'Muy alta'];
@endphp

<section aria-labelledby="recorrido" {{ $attributes }}>
    <h2 id="recorrido" class="text-3xl font-semibold sm:text-4xl">
        El recorrido.
        <span class="text-texto-secundario">De Piscacucho a Machu Picchu en cuatro días.</span>
    </h2>
    <p class="mt-4 max-w-[60ch] text-[17px] leading-relaxed text-texto-secundario">
        Los cinco hitos del Camino Inca de 4 días. Abrí cada uno para ver cómo aclimatarte y qué tan exigente es el tramo.
        Las altitudes y las distancias son aproximadas.
    </p>

    <ol class="mt-10 space-y-6">
        @foreach ($hitos as $indice => $hito)
            <li class="relative grid grid-cols-[2.5rem_1fr] gap-x-3 sm:gap-x-6">
                {{-- Tramo de la línea que une este hito con el siguiente, o con el botón final. --}}
                <span class="absolute top-10 -bottom-6 left-5 -ml-px border-l-2 border-dashed border-divisor" aria-hidden="true"></span>

                <span class="flex size-10 items-center justify-center rounded-full bg-aviso font-semibold text-enlace tabular-nums" aria-hidden="true">
                    {{ $indice + 1 }}
                </span>

                <article class="min-w-0 overflow-hidden rounded-[18px] bg-tarjeta shadow-tarjeta">
                    <div class="md:flex">
                        <div class="aspect-video md:aspect-auto md:min-h-64 md:w-2/5 md:shrink-0">
                            @if ($hito['foto'])
                                <img src="{{ asset($hito['foto']) }}" alt="{{ $hito['nombre'] }}" loading="lazy" class="size-full object-cover">
                            @else
                                {{-- Lugar de la foto mientras no exista. --}}
                                <div class="flex size-full items-center justify-center bg-linear-to-br from-aviso to-fondo" aria-hidden="true">
                                    <svg class="w-28 text-accion/30" viewBox="0 0 120 64" fill="none" stroke="currentColor" stroke-width="2.5"
                                         stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M4 60 38 20l16 18 22-30 40 52" />
                                        <circle cx="96" cy="14" r="7" />
                                    </svg>
                                </div>
                            @endif
                        </div>

                        <div class="p-6 sm:p-8 md:flex-1">
                            <p class="text-sm font-medium text-texto-secundario">Día {{ $hito['dia'] }}</p>
                            <h3 class="mt-1 text-2xl font-semibold">{{ $hito['nombre'] }}</h3>
                            <p class="mt-3 text-[17px] leading-relaxed text-texto-secundario">{{ $hito['resumen'] }}</p>

                            <dl class="mt-6 flex flex-wrap gap-x-10 gap-y-4">
                                <div>
                                    <dt class="text-sm text-texto-secundario">Altitud</dt>
                                    <dd class="mt-1 text-2xl font-semibold tabular-nums">
                                        {{ number_format($hito['altitud'], 0, ',', '.') }}
                                        <abbr title="metros sobre el nivel del mar" class="text-base font-normal text-texto-secundario no-underline">msnm</abbr>
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-sm text-texto-secundario">Desde el inicio</dt>
                                    <dd class="mt-1 text-2xl font-semibold tabular-nums">
                                        {{ $hito['kilometro'] }}
                                        <span class="text-base font-normal text-texto-secundario">km</span>
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-sm text-texto-secundario">Dificultad</dt>
                                    <dd class="mt-1 flex h-8 items-center gap-2.5">
                                        <span class="flex gap-1" aria-hidden="true">
                                            @foreach (array_keys($nivelesDificultad) as $nivel)
                                                <span class="h-2 w-5 rounded-full {{ $nivel <= $hito['dificultad'] ? 'bg-accion' : 'bg-divisor' }}"></span>
                                            @endforeach
                                        </span>
                                        <span class="text-[17px] font-semibold">{{ $nivelesDificultad[$hito['dificultad']] }}</span>
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    <details class="group border-t border-divisor">
                        <summary class="flex min-h-14 cursor-pointer list-none items-center justify-between gap-4 px-6 text-[17px] text-enlace transition-colors duration-150 ease-out hover:bg-fondo focus-visible:-outline-offset-2 sm:px-8 [&::-webkit-details-marker]:hidden">
                            <span>Aclimatación y dificultad<span class="sr-only"> en {{ $hito['nombre'] }}</span></span>
                            <svg class="size-5 shrink-0 transition-transform duration-200 ease-out group-open:rotate-180" viewBox="0 0 20 20" fill="none"
                                 stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="m5 7.5 5 5 5-5" />
                            </svg>
                        </summary>

                        <div class="grid gap-8 px-6 pt-2 pb-8 sm:px-8 md:grid-cols-2 md:gap-12">
                            <div>
                                <h4 class="text-[17px] font-semibold">Aclimatación</h4>
                                <ul class="mt-3 space-y-3">
                                    @foreach ($hito['aclimatacion'] as $recomendacion)
                                        <li class="flex gap-3 text-[15px] leading-relaxed">
                                            <svg class="mt-1 size-4 shrink-0 text-accion" viewBox="0 0 16 16" fill="none" stroke="currentColor"
                                                 stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="m3.5 8.5 3 3 6-7" />
                                            </svg>
                                            {{ $recomendacion }}
                                        </li>
                                    @endforeach
                                </ul>
                            </div>

                            <div>
                                <h4 class="text-[17px] font-semibold">Dificultad del tramo</h4>
                                <p class="mt-3 text-[15px] leading-relaxed">{{ $hito['detalleDificultad'] }}</p>
                            </div>
                        </div>
                    </details>
                </article>
            </li>
        @endforeach
    </ol>

    {{-- El final del camino: la línea del último hito llega hasta acá. --}}
    <div class="mt-6 grid grid-cols-[2.5rem_1fr] gap-x-3 sm:gap-x-6">
        <span class="flex size-10 items-center justify-center rounded-full bg-accion text-white" aria-hidden="true">
            <svg class="size-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                <path d="M5 17.5v-14M5 4h9.5l-2 3.5 2 3.5H5" />
            </svg>
        </span>

        <div class="min-w-0 rounded-[18px] bg-linear-to-br from-tarjeta to-aviso px-6 py-10 shadow-tarjeta-elevada sm:px-12 sm:py-14">
            <h3 class="max-w-2xl text-3xl font-semibold sm:text-4xl">
                Tu lugar en el Camino.
                <span class="text-texto-secundario">Elegí cuándo salir.</span>
            </h3>
            <p class="mt-4 max-w-[52ch] text-[17px] leading-relaxed text-texto-secundario">
                Las salidas son los lunes y se reservan con al menos {{ config('reserva.meses_anticipacion_minima') }} meses de
                anticipación. Elegí una fecha con lugares disponibles y reservá para vos y tus acompañantes.
            </p>
            <x-boton :href="$href" class="mt-8">Ver fechas de salida</x-boton>
        </div>
    </div>
</section>
