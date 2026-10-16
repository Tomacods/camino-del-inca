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
