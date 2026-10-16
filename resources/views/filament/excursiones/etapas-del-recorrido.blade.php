{{-- Reportar Etapa (CU-12), paso 2: las etapas planificadas del recorrido, en orden, con la última reportada y la siguiente. --}}
<ol style="margin:0;padding-left:1.5rem;list-style:decimal;font-size:0.875rem;line-height:1.6">
    @foreach ($etapas as $etapa)
        <li>
            @if ($etapa->id_etapa === $idSiguiente)
                <strong>{{ $etapa->nombre }} · a reportar</strong>
            @elseif ($etapa->id_etapa === $idEtapaActual)
                {{ $etapa->nombre }} · última reportada
            @else
                {{ $etapa->nombre }}
            @endif
        </li>
    @endforeach
</ol>

{{-- A3 (4.6–4.7): si falla la conexión al enviar el reporte, el sistema informa que la etapa no pudo registrarse y permite
     reintentar. Livewire muestra el aviso cuando el navegador se queda sin red y lo oculta cuando vuelve. Si el guía toca
     Reportar sin conexión, el pedido falla y el botón queda deshabilitado aunque vuelva la señal: para reintentar hay que
     recargar la página. Reintentar no tiene riesgo: si el primer pedido sí había llegado, el segundo avisa «ya se
     reportó» y no avanza otra etapa (ver Excursion::actualizarEtapaActual()). Va sin modificador (.block): Livewire oculta
     de entrada sólo [wire:offline], y con otro nombre de atributo el aviso se vería también con conexión. --}}
<p wire:offline role="alert" style="margin:0.75rem 0 0;padding:0.5rem 0.75rem;border-left:3px solid var(--warning-500);font-size:0.875rem;font-weight:600;text-align:left">
    Sin conexión: la etapa todavía no se registró. Cuando vuelva la señal, recargá la página y reportá de nuevo.
</p>
