{{--
    El recorrido de la portada como un paseo con el scroll: una foto de fondo por hito, que pasa a la siguiente y se
    acerca de a poco a medida que se baja (resources/js/recorrido.js), y encima la tarjeta de cada hito, que se abre para
    leer cómo aclimatarse y qué tan exigente es el tramo. Al final, el botón para elegir la fecha de salida.
    La idea es la de midudev/mcp-higgsfield-landing, con fotos fijas en vez de video y sin GSAP.
    <x-hitos-del-camino class="mt-24" />
    <x-hitos-del-camino href="#fechas" />   (en la pantalla de un paquete, para ir directo a sus fechas)
--}}
@props(['href' => '/paquetes'])

@php
    // Los nombres son los de las etapas del «Camino Inca de 4 días» que carga RecorridoSeeder. La altitud, la distancia
    // y las recomendaciones no están en la tabla ETAPA (sólo tiene nombre y orden): guardarlas ahí sería un cambio de
    // esquema, así que por ahora son texto de la portada. Altitudes y distancias aproximadas.
    // Cada foto muestra el lugar de su hito. Las de Machu Picchu las sacó la tía de Tomás; las otras son de Wikimedia
    // Commons, con licencia CC BY o CC BY-SA, que piden nombrar al autor y la licencia (los créditos van al final de la
    // sección). Cada una está en public/img/recorrido en dos recortes: horizontal (computadora) y vertical (celular).
    // Las fotos propias no tienen licencia, sitio ni enlace.
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
            'foto' => [
                'archivo' => 'img/recorrido/km-82',
                'muestra' => 'Entrada al Camino Inca en Piscacucho',
                'autor' => 'Sashimi-b',
                'licencia' => 'CC BY 4.0',
                'enlaceLicencia' => 'https://creativecommons.org/licenses/by/4.0/',
                'sitio' => 'Wikimedia Commons',
                'enlace' => 'https://commons.wikimedia.org/wiki/File:Inca_Trail_Entrance.jpg',
            ],
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
            'foto' => [
                'archivo' => 'img/recorrido/wayllabamba',
                'muestra' => 'Wayllabamba, en el Camino Inca',
                'autor' => 'Josefito123',
                'licencia' => 'CC BY-SA 4.0',
                'enlaceLicencia' => 'https://creativecommons.org/licenses/by-sa/4.0/',
                'sitio' => 'Wikimedia Commons',
                'enlace' => 'https://commons.wikimedia.org/wiki/File:20250713_154756_-_Huayllabamba_en_el_camino_inca.jpg',
            ],
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
            'foto' => [
                'archivo' => 'img/recorrido/abra-warmiwanusca',
                'muestra' => 'Vista desde el Abra Warmiwañusca',
                'autor' => 'Josefito123',
                'licencia' => 'CC BY-SA 4.0',
                'enlaceLicencia' => 'https://creativecommons.org/licenses/by-sa/4.0/',
                'sitio' => 'Wikimedia Commons',
                'enlace' => 'https://commons.wikimedia.org/wiki/File:20250714_115010_Abra_Warmiwa%C3%B1usca_(4.216_metros)_en_el_camino_inca.jpg',
            ],
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
            'foto' => [
                'archivo' => 'img/recorrido/winay-wayna',
                'muestra' => 'Ruinas y andenes de Wiñay Wayna',
                'autor' => 'Sashimi-b',
                'licencia' => 'CC BY 4.0',
                'enlaceLicencia' => 'https://creativecommons.org/licenses/by/4.0/',
                'sitio' => 'Wikimedia Commons',
                'enlace' => 'https://commons.wikimedia.org/wiki/File:WinayWayna-lowerstructure.jpg',
            ],
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
            'foto' => [
                'archivo' => 'img/recorrido/machu-picchu',
                'muestra' => 'Machu Picchu',
                'autor' => 'la tía de Tomás Da Silva (Grupo 11)',
                'licencia' => null,
                'enlaceLicencia' => null,
                'sitio' => null,
                'enlace' => null,
            ],
        ],
    ];

    // La foto que acompaña al botón final.
    $fotoFinal = [
        'archivo' => 'img/recorrido/llegada',
        'muestra' => 'Machu Picchu',
        'autor' => 'la tía de Tomás Da Silva (Grupo 11)',
        'licencia' => null,
        'enlaceLicencia' => null,
        'sitio' => null,
        'enlace' => null,
    ];

    $fotos = [...array_column($hitos, 'foto'), $fotoFinal];

    $nivelesDificultad = [1 => 'Baja', 2 => 'Moderada', 3 => 'Alta', 4 => 'Muy alta'];
@endphp

<section aria-labelledby="recorrido" {{ $attributes }}>
    <h2 id="recorrido" class="text-3xl font-semibold sm:text-4xl">
        El recorrido.
        <span class="text-texto-secundario">De Piscacucho a Machu Picchu en cuatro días.</span>
    </h2>
    <p class="mt-4 max-w-[60ch] text-[17px] leading-relaxed text-texto-secundario">
        Bajá para recorrer los cinco hitos del Camino Inca de 4 días. Abrí cada uno para ver cómo aclimatarte y qué tan
        exigente es el tramo. Las altitudes y las distancias son aproximadas.
    </p>

    {{-- Ocupa todo el ancho de la ventana aunque la página tenga un ancho máximo (el html recorta lo que sobra). --}}
    <div class="relative mt-10 ml-[calc(50%-50vw)] w-screen">
        {{-- El escenario queda fijo mientras pasan las paradas. Una foto encima de la otra: el script decide cuál se ve. --}}
        <div data-escenario class="sticky top-0 h-lvh overflow-hidden bg-gris-fondo">
            <div aria-hidden="true">
                @foreach ($fotos as $indice => $foto)
                    <div data-foto class="absolute inset-0" @if ($indice > 0) style="opacity: 0" @endif>
                        {{-- Salvo la primera, las fotos llevan la dirección en data-: el script las pide cuando el visitante se
                             acerca, así la portada no baja de entrada fotos que quizás nadie ve. --}}
                        @php($prefijo = $indice === 0 ? '' : 'data-')
                        <picture class="block size-full">
                            <source media="(orientation: portrait)" {{ $prefijo }}srcset="{{ asset($foto['archivo'].'-vertical.webp') }}">
                            <img {{ $prefijo }}src="{{ asset($foto['archivo'].'-horizontal.webp') }}" alt="" loading="lazy" class="size-full object-cover">
                        </picture>
                        <p class="absolute top-18 right-4 max-w-[calc(100%-2rem)] rounded-xl bg-texto/70 px-3 py-1 text-sm text-white transition-opacity duration-300 sm:right-6">
                            {{ $foto['muestra'] }}
                        </p>
                    </div>
                @endforeach
            </div>

            {{-- Riel de hitos, en pantallas grandes: marca el hito actual y lleva a cualquiera. --}}
            <nav aria-label="Hitos del recorrido" class="absolute top-1/2 right-6 hidden -translate-y-1/2 lg:block">
                <span class="absolute top-3 right-[5px] bottom-3 w-0.5 rounded-full bg-tarjeta/50" aria-hidden="true">
                    <span data-relleno class="absolute inset-0 origin-top scale-y-0 rounded-full bg-accion"></span>
                </span>
                <ol class="flex flex-col items-end gap-4">
                    @foreach ($hitos as $indice => $hito)
                        <li>
                            {{-- El anillo blanco del foco y el borde del punto hacen que se vean sobre cualquier foto. --}}
                            <a href="#hito-{{ $indice + 1 }}" data-ir-a
                               class="group flex min-h-6 items-center gap-3 rounded-full focus-visible:ring-2 focus-visible:ring-tarjeta">
                                <span class="rounded-full bg-tarjeta/90 px-3 py-1 text-sm whitespace-nowrap text-texto opacity-0 shadow-tarjeta transition-opacity duration-500 group-hover:opacity-100 group-focus-visible:opacity-100 group-aria-[current=step]:opacity-100">
                                    {{ $hito['nombre'] }}
                                </span>
                                <span class="relative size-3 rounded-full bg-tarjeta ring-2 ring-texto/60 transition-[scale,background-color] duration-500 group-aria-[current=step]:scale-150 group-aria-[current=step]:bg-accion"></span>
                            </a>
                        </li>
                    @endforeach
                </ol>
            </nav>
        </div>

        {{-- Las paradas pasan por encima del escenario. Sólo las tarjetas reciben clics, así el riel de abajo sigue andando. --}}
        <div class="pointer-events-none relative -mt-[100lvh]">
            <ol>
                @foreach ($hitos as $indice => $hito)
                    <li id="hito-{{ $indice + 1 }}" data-parada class="flex min-h-[150lvh] items-start pt-[25lvh]">
                        <div class="mx-auto w-full max-w-5xl px-4 sm:px-6">
                            <article class="pointer-events-auto max-w-lg overflow-hidden rounded-[18px] bg-tarjeta shadow-tarjeta-elevada">
                                <div class="p-6 sm:p-8">
                                    <p class="text-sm font-medium text-texto-secundario">
                                        Hito {{ $indice + 1 }} de {{ count($hitos) }} · Día {{ $hito['dia'] }}
                                    </p>
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

                                <details class="group border-t border-divisor">
                                    <summary class="flex min-h-14 cursor-pointer list-none items-center justify-between gap-4 px-6 text-[17px] text-enlace transition-colors duration-150 ease-out hover:bg-fondo focus-visible:-outline-offset-2 sm:px-8 [&::-webkit-details-marker]:hidden">
                                        <span>Aclimatación y dificultad<span class="sr-only"> en {{ $hito['nombre'] }}</span></span>
                                        <svg class="size-5 shrink-0 transition-transform duration-200 ease-out group-open:rotate-180" viewBox="0 0 20 20" fill="none"
                                             stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="m5 7.5 5 5 5-5" />
                                        </svg>
                                    </summary>

                                    <div class="space-y-6 px-6 pt-2 pb-8 sm:px-8">
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
                        </div>
                    </li>
                @endforeach
            </ol>

            {{-- El final del camino, sobre la última foto. --}}
            <div data-parada class="flex min-h-lvh items-center">
                <div class="mx-auto w-full max-w-5xl px-4 sm:px-6">
                    <div class="pointer-events-auto max-w-2xl rounded-[18px] bg-tarjeta px-6 py-10 shadow-tarjeta-elevada sm:px-12 sm:py-14">
                        <h3 class="text-3xl font-semibold sm:text-4xl">
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
            </div>
        </div>
    </div>

    <p class="mt-4 text-sm leading-relaxed text-texto-secundario">
        Fotos:
        {{-- Dos fotos con el mismo crédito (las de Machu Picchu) se nombran una sola vez. --}}
        @foreach (collect($fotos)->unique(fn ($foto) => $foto['muestra'].$foto['autor']) as $foto)
            @if ($foto['enlace'])
                <a href="{{ $foto['enlace'] }}" class="text-enlace underline">{{ $foto['muestra'] }}</a>,
            @else
                {{ $foto['muestra'] }},
            @endif
            {{-- En una sola línea, para que no quede un espacio antes del punto y coma. --}}
            de {{ $foto['autor'] }}@if ($foto['licencia']) (<a href="{{ $foto['enlaceLicencia'] }}" class="text-enlace underline">{{ $foto['licencia'] }}</a>) en {{ $foto['sitio'] }}@endif{{ $loop->last ? '.' : ';' }}
        @endforeach
        {{-- Las licencias CC BY y CC BY-SA piden avisar si la foto se modificó; las CC BY-SA, además, compartirla igual. --}}
        Las de Wikimedia Commons están recortadas, y las CC BY-SA se comparten con la misma licencia.
    </p>
</section>
