<?php

use App\Jobs\LiberarCupoRetenido;
use App\Models\Excursion;
use App\Models\Reserva;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Reservar')] class extends Component
{
    // Lo que no escribe el cliente va con #[Locked]: el navegador no lo puede cambiar, sólo las acciones de acá.
    #[Locked]
    public int $idPaquete;

    #[Locked]
    public string $fechaSalida;

    #[Locked]
    public int $paso = 1;

    // Por qué no se puede reservar esta salida: 'no-disponible', 'anticipacion', 'sin-cupo' o 'cupo-ocupado' (A7: se
    // ocupó al confirmar). Null si se puede.
    #[Locked]
    public ?string $impedimento = null;

    public string $correoElectronico = '';

    // Sin tipo: el campo numérico puede llegar vacío o con texto, y eso lo tiene que informar la validación.
    public $cantidadIntegrantes = 1;

    // El integrante que se está cargando (su posición, desde 0) y sus datos.
    #[Locked]
    public int $integranteActual = 0;

    public string $nombre = '';

    public string $apellido = '';

    public string $documentoPasaporte = '';

    public bool $equipoCamping = false;

    // Todavía no son excursionistas: se guardan con la reserva recién con el primer pago (CU-15).
    #[Locked]
    public array $integrantes = [];

    #[Locked]
    public int $nochesExtraAntes = 0;

    #[Locked]
    public int $nochesExtraDespues = 0;

    public function mount(int $idPaquete, string $fechaSalida): void
    {
        $this->idPaquete = $idPaquete;
        $this->fechaSalida = $fechaSalida;

        $this->iniciarReserva();
    }

    // En el orden del caso de uso: que exista la salida, la anticipación (A1), que el paquete esté activo y el cupo (A3).
    protected function iniciarReserva(): void
    {
        $excursion = $this->excursion;
        $hoy = now();

        $this->impedimento = match (true) {
            $excursion === null => 'no-disponible',
            ! $excursion->cumpleAnticipacionMinima($hoy) => 'anticipacion',
            ! $excursion->admiteReserva($hoy) => 'no-disponible',
            $excursion->obtenerCupoDisponible() <= 0 => 'sin-cupo',
            default => null,
        };
    }

    // Se busca en cada pedido, para que el cupo disponible esté al día. En PostgreSQL una fecha mal escrita rompe la
    // consulta: por eso se valida el formato antes de buscar.
    #[Computed]
    public function excursion(): ?Excursion
    {
        if (Validator::make(['fechaSalida' => $this->fechaSalida], ['fechaSalida' => 'date_format:Y-m-d'])->fails()) {
            return null;
        }

        return Excursion::buscarPorFechaSalida($this->idPaquete, $this->fechaSalida);
    }

    protected function rules(): array
    {
        return [
            'correoElectronico' => ['bail', 'required', 'email', 'max:100'],
            'cantidadIntegrantes' => ['bail', 'required', 'integer', 'min:1', function ($atributo, $valor, $fallar) {
                if (! $this->excursion->tieneCupoPara((int) $valor)) {
                    $fallar($this->mensajeLugaresDisponibles());
                }
            }],
            'nombre' => ['bail', 'required', 'max:50'],
            'apellido' => ['bail', 'required', 'max:50'],
            'documentoPasaporte' => ['bail', 'required', 'regex:/^[A-Za-z0-9]{1,20}$/', function ($atributo, $valor, $fallar) {
                if ($this->documentoRepetido($valor)) {
                    $fallar('Ya cargaste un integrante con ese documento.');
                }
            }],
            'equipoCamping' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'correoElectronico.required' => 'Ingresá tu correo electrónico.',
            'correoElectronico.email' => 'El correo no tiene un formato válido.',
            'correoElectronico.max' => 'El correo no tiene un formato válido.',
            'cantidadIntegrantes.required' => 'Indicá cuántas personas viajan.',
            'cantidadIntegrantes.integer' => 'Indicá cuántas personas viajan.',
            'cantidadIntegrantes.min' => 'Indicá cuántas personas viajan.',
            'nombre.required' => 'Ingresá el nombre.',
            'nombre.max' => 'El nombre no puede tener más de 50 caracteres.',
            'apellido.required' => 'Ingresá el apellido.',
            'apellido.max' => 'El apellido no puede tener más de 50 caracteres.',
            'documentoPasaporte.required' => 'El documento o pasaporte es obligatorio.',
            'documentoPasaporte.regex' => 'El documento o pasaporte sólo puede tener letras y números, hasta 20.',
        ];
    }

    // Cada dato se valida cuando el cliente deja el campo (wire:model.live.blur).
    public function updated(string $propiedad): void
    {
        // El correo se guarda en minúsculas y sin espacios en las puntas, para que después el cliente encuentre su
        // reserva aunque lo escriba con otras mayúsculas. El campo muestra el correo ya convertido.
        if ($propiedad === 'correoElectronico') {
            $this->correoElectronico = mb_strtolower(trim($this->correoElectronico));
        }

        if (! $this->enPantalla(1)) {
            return;
        }

        $this->validateOnly($propiedad);

        if ($propiedad === 'cantidadIntegrantes') {
            $this->ajustarIntegrantes();
        }
    }

    // Al avanzar se valida de nuevo todo junto, también el cupo. Desde el último integrante se pasa a la pantalla 2.
    public function siguienteIntegrante(): void
    {
        if (! $this->enPantalla(1)) {
            return;
        }

        $this->validate();
        $this->guardarIntegrante();

        if ($this->integranteActual + 1 < (int) $this->cantidadIntegrantes) {
            $this->integranteActual++;
            $this->cargarIntegrante();

            return;
        }

        if (count($this->integrantes) === (int) $this->cantidadIntegrantes) {
            $this->paso = 2;
        }
    }

    // Guarda lo que se iba cargando, aunque esté incompleto, para no perderlo: se valida al volver a avanzar.
    public function anteriorIntegrante(): void
    {
        if (! $this->enPantalla(1) || $this->integranteActual === 0) {
            return;
        }

        $this->guardarIntegrante();
        $this->integranteActual--;
        $this->cargarIntegrante();
    }

    public function volver(): void
    {
        if (! $this->enPantalla(2)) {
            return;
        }

        $this->resetErrorBag('nochesExtra');
        $this->paso = 1;
        $this->integranteActual = count($this->integrantes) - 1;
        $this->cargarIntegrante();
    }

    // A6: se descarta lo cargado y, si ya se había retenido el cupo (el cliente volvió atrás desde el pago), se libera.
    public function cancelar(): void
    {
        $this->descartarDatosReserva();
        $this->liberarReservaEnCurso();

        $this->redirect('/');
    }

    // Pasos 18 a 21: vuelve a validar el cupo y lo retiene, guarda la reserva en curso en la sesión y deriva a Pagar
    // Reserva (CU-15). Todavía no se crea la reserva: se crea con el primer pago.
    public function confirmarReserva(): void
    {
        if (! $this->enPantalla(2) || count($this->integrantes) !== (int) $this->cantidadIntegrantes) {
            return;
        }

        // El correo pasa a la sesión y de ahí a la reserva: se valida otra vez, por si cambió desde la pantalla 1.
        $this->validateOnly('correoElectronico');

        if (! Reserva::validarNochesExtra($this->nochesExtraAntes, $this->nochesExtraDespues)) {
            $this->addError('nochesExtra', $this->mensajeMaximoNoches());

            return;
        }

        // Si el cliente volvió atrás y confirma de nuevo, primero se libera lo que había retenido.
        $this->liberarReservaEnCurso();

        // retenerCupo() verifica el cupo y lo retiene con la fila bloqueada: entre una cosa y la otra no se puede meter
        // otro cliente. Si ya no alcanza, es el curso A7.
        $cantidadPlazas = count($this->integrantes);

        if (! $this->excursion->retenerCupo($cantidadPlazas)) {
            $this->descartarDatosReserva();
            $this->impedimento = 'cupo-ocupado';

            return;
        }

        $idRetencion = (string) Str::uuid();
        $vence = now()->addMinutes(config('reserva.minutos_retencion'));

        // Si el cliente no paga a tiempo, esta tarea libera las plazas al vencer el plazo, aunque haya cerrado el navegador.
        LiberarCupoRetenido::dispatch($this->excursion->id_excursion, $cantidadPlazas, $idRetencion)->delay($vence);

        session(['reserva_en_curso' => [
            'id_retencion' => $idRetencion,
            'id_excursion' => $this->excursion->id_excursion,
            'correo_electronico' => $this->correoElectronico,
            'noches_extra_antes' => $this->nochesExtraAntes,
            'noches_extra_despues' => $this->nochesExtraDespues,
            'integrantes' => array_map(fn ($integrante) => [
                'nombre' => $integrante['nombre'],
                'apellido' => $integrante['apellido'],
                'documento_pasaporte' => $integrante['documentoPasaporte'],
                'equipo_camping' => $integrante['equipoCamping'],
            ], $this->integrantes),
            'vence' => $vence->toIso8601String(),
        ]]);

        $this->redirect('/reservar/pago');
    }

    public function sumarNoche(string $momento): void
    {
        if (! $this->enPantalla(2)) {
            return;
        }

        $antes = $this->nochesExtraAntes + ($momento === 'antes' ? 1 : 0);
        $despues = $this->nochesExtraDespues + ($momento === 'despues' ? 1 : 0);

        if (! Reserva::validarNochesExtra($antes, $despues)) {
            $this->addError('nochesExtra', $this->mensajeMaximoNoches());

            return;
        }

        $this->resetErrorBag('nochesExtra');
        $this->nochesExtraAntes = $antes;
        $this->nochesExtraDespues = $despues;
    }

    public function restarNoche(string $momento): void
    {
        if (! $this->enPantalla(2)) {
            return;
        }

        $this->resetErrorBag('nochesExtra');

        if ($momento === 'antes') {
            $this->nochesExtraAntes = max(0, $this->nochesExtraAntes - 1);
        } elseif ($momento === 'despues') {
            $this->nochesExtraDespues = max(0, $this->nochesExtraDespues - 1);
        }
    }

    // Un campo se marca como válido cuando tiene un dato y ese dato cumple su regla.
    #[Computed]
    public function camposValidos(): array
    {
        $reglas = $this->rules();
        $campos = ['correoElectronico', 'cantidadIntegrantes', 'nombre', 'apellido', 'documentoPasaporte'];

        return array_values(array_filter($campos, fn ($campo) => filled($this->{$campo})
            && Validator::make([$campo => $this->{$campo}], [$campo => $reglas[$campo]])->passes()));
    }

    // Las acciones de una pantalla no hacen nada desde la otra, ni cuando la salida no se puede reservar.
    private function enPantalla(int $paso): bool
    {
        return $this->impedimento === null && $this->paso === $paso;
    }

    // Si baja la cantidad se descartan los integrantes que sobran; si sube, los que faltan se piden al avanzar.
    private function ajustarIntegrantes(): void
    {
        $cantidad = (int) $this->cantidadIntegrantes;
        $this->integrantes = array_slice($this->integrantes, 0, $cantidad);

        if ($this->integranteActual >= $cantidad) {
            $this->integranteActual = $cantidad - 1;
            $this->cargarIntegrante();
        }
    }

    private function guardarIntegrante(): void
    {
        $this->integrantes[$this->integranteActual] = [
            'nombre' => trim($this->nombre),
            'apellido' => trim($this->apellido),
            'documentoPasaporte' => trim($this->documentoPasaporte),
            'equipoCamping' => $this->equipoCamping,
        ];
    }

    private function cargarIntegrante(): void
    {
        $integrante = $this->integrantes[$this->integranteActual] ?? null;

        $this->nombre = $integrante['nombre'] ?? '';
        $this->apellido = $integrante['apellido'] ?? '';
        $this->documentoPasaporte = $integrante['documentoPasaporte'] ?? '';
        $this->equipoCamping = $integrante['equipoCamping'] ?? false;

        $this->resetValidation(['nombre', 'apellido', 'documentoPasaporte']);
    }

    // Sólo contra los anteriores: «Anterior» guarda el actual sin validar, y compararlo con los posteriores trabaría
    // al integrante al que se vuelve. Los posteriores se validan al volver a pasar por ellos, antes de la pantalla 2.
    // Sin distinguir mayúsculas: «aac118204» y «AAC118204» son el mismo documento.
    private function documentoRepetido(string $documento): bool
    {
        foreach ($this->integrantes as $posicion => $integrante) {
            if ($posicion < $this->integranteActual && strcasecmp($integrante['documentoPasaporte'], $documento) === 0) {
                return true;
            }
        }

        return false;
    }

    // DS-14, «el cupo se ocupó antes de confirmar»: se vacía todo lo cargado.
    private function descartarDatosReserva(): void
    {
        $this->reset([
            'paso', 'correoElectronico', 'cantidadIntegrantes', 'integranteActual', 'nombre', 'apellido',
            'documentoPasaporte', 'equipoCamping', 'integrantes', 'nochesExtraAntes', 'nochesExtraDespues',
        ]);
        $this->resetValidation();
    }

    // Libera en el momento la retención que haya en la sesión y la saca de ahí. Si la tarea demorada ya la había
    // liberado, no descuenta de nuevo.
    private function liberarReservaEnCurso(): void
    {
        $enCurso = session()->pull('reserva_en_curso');

        if ($enCurso !== null) {
            LiberarCupoRetenido::dispatchSync($enCurso['id_excursion'], count($enCurso['integrantes']), $enCurso['id_retencion']);
        }
    }

    private function mensajeMaximoNoches(): string
    {
        return 'Podés sumar hasta '.config('reserva.maximo_noches_extra').' noches extra en total.';
    }

    private function mensajeLugaresDisponibles(): string
    {
        $lugares = $this->excursion->obtenerCupoDisponible();
        $quedan = $lugares === 1 ? 'Queda 1 lugar' : 'Quedan '.$lugares.' lugares';

        return $quedan.' en esta salida. Elegí otra fecha u otro paquete si necesitás más.';
    }
};

?>

@php
    $maximoNoches = config('reserva.maximo_noches_extra');
    $minutosRetencion = config('reserva.minutos_retencion');
    $plazoRetencion = $minutosRetencion === 1 ? '1 minuto' : $minutosRetencion.' minutos';
@endphp

<div class="mx-auto max-w-3xl">
    @if ($impedimento)
        {{-- La salida no se puede reservar: se avisa por qué y no se muestra el formulario. --}}
        <h1 class="text-4xl font-semibold sm:text-5xl">Reservar.</h1>

        @if ($impedimento === 'anticipacion')
            <x-aviso tipo="error" titulo="Esta salida ya no se puede reservar" class="mt-8">
                Las reservas se hacen con al menos {{ config('reserva.meses_anticipacion_minima') }} meses de anticipación. Elegí otra fecha de salida.
            </x-aviso>
        @elseif ($impedimento === 'sin-cupo')
            <x-aviso tipo="error" class="mt-8">No quedan lugares en esta salida. Elegí otra fecha u otro paquete.</x-aviso>
        @elseif ($impedimento === 'cupo-ocupado')
            <x-aviso tipo="error" titulo="Los lugares ya no están disponibles" class="mt-8">
                Mientras cargabas los datos se ocuparon los lugares que quedaban en esta salida. Elegí otra fecha u otro paquete.
            </x-aviso>
        @else
            <x-aviso tipo="error" class="mt-8">Esta salida no está disponible para reservar.</x-aviso>
        @endif

        <x-boton href="/paquetes" class="mt-6">Ver los paquetes</x-boton>
    @else
        @php
            $excursion = $this->excursion;
            $lugares = $excursion->obtenerCupoDisponible();
        @endphp

        <header>
            <h1 class="text-4xl font-semibold sm:text-5xl">{{ $excursion->paquete->nombre }}</h1>
            <p class="mt-3 text-[17px] text-texto-secundario">
                Salida del <span class="tabular-nums">{{ $excursion->getFechaSalida()->format('d/m/Y') }}</span>
                · {{ $lugares === 1 ? 'Queda 1 lugar' : 'Quedan '.$lugares.' lugares' }}
            </p>
        </header>

        {{-- Los dos pasos del formulario: la línea de arriba marca hasta dónde se llegó. --}}
        <ol class="mt-8 grid grid-cols-2 gap-3 text-[15px]" aria-label="Pasos de la reserva">
            @foreach ([1 => 'Titular e integrantes', 2 => 'Noches extra y resumen'] as $numero => $nombrePaso)
                <li @if ($paso === $numero) aria-current="step" @endif
                    class="border-t-2 pt-3 {{ $paso >= $numero ? 'border-accion text-texto' : 'border-divisor text-texto-secundario' }}">
                    <span class="font-semibold tabular-nums">{{ $numero }} de 2</span> · {{ $nombrePaso }}
                </li>
            @endforeach
        </ol>

        @if ($paso === 1)
            @php
                $validos = $this->camposValidos;
                $totalIntegrantes = max(1, (int) $cantidadIntegrantes);
                $esElUltimo = $integranteActual + 1 >= $totalIntegrantes;
            @endphp

            {{-- Pantalla 1 de 2: titular e integrantes --}}
            <form wire:submit="siguienteIntegrante" class="mt-8 grid gap-5">
                <x-tarjeta titulo="Titular de la reserva">
                    <div class="grid gap-5 sm:grid-cols-[1fr_200px]">
                        <x-campo nombre="correoElectronico" etiqueta="Correo electrónico" type="email"
                                 wire:model.live.blur="correoElectronico" placeholder="nombre@correo.com" autocomplete="email"
                                 :valido="in_array('correoElectronico', $validos)" />
                        <x-campo nombre="cantidadIntegrantes" etiqueta="Cantidad de integrantes" type="number" min="1"
                                 inputmode="numeric" wire:model.live.blur="cantidadIntegrantes"
                                 :valido="in_array('cantidadIntegrantes', $validos)" />
                    </div>
                </x-tarjeta>

                <x-tarjeta :titulo="'Integrante '.($integranteActual + 1).' de '.$totalIntegrantes">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-campo nombre="nombre" etiqueta="Nombre" wire:model.live.blur="nombre" autocomplete="off"
                                 :valido="in_array('nombre', $validos)" />
                        <x-campo nombre="apellido" etiqueta="Apellido" wire:model.live.blur="apellido" autocomplete="off"
                                 :valido="in_array('apellido', $validos)" />
                    </div>

                    <x-campo nombre="documentoPasaporte" etiqueta="Documento o pasaporte" class="mt-5"
                             ayuda="Sólo letras y números, sin espacios ni guiones." wire:model.live.blur="documentoPasaporte"
                             autocomplete="off" :valido="in_array('documentoPasaporte', $validos)" />

                    <label class="mt-5 flex min-h-14 cursor-pointer items-center gap-3 rounded-xl border border-borde-campo px-4 py-3
                                  text-[17px] transition-colors has-checked:border-accion has-checked:bg-aviso">
                        <input type="checkbox" wire:model="equipoCamping" class="size-5 shrink-0 accent-accion">
                        Lleva equipo de camping (USD {{ number_format($excursion->paquete->costo_equipo_camping, 0, ',', '.') }})
                    </label>
                </x-tarjeta>

                <div class="mt-3 flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <x-boton variante="secundario" wire:click="cancelar">Cancelar</x-boton>

                    <div class="flex flex-col-reverse gap-3 sm:flex-row">
                        @if ($integranteActual > 0)
                            <x-boton variante="secundario" wire:click="anteriorIntegrante">Anterior</x-boton>
                        @endif
                        <x-boton type="submit">{{ $esElUltimo ? 'Continuar' : 'Siguiente integrante' }}</x-boton>
                    </div>
                </div>
            </form>
        @else
            @php
                $conEquipo = count(array_filter(array_column($integrantes, 'equipoCamping')));
                $noches = array_filter([
                    $nochesExtraAntes > 0 ? $nochesExtraAntes.' antes del recorrido' : null,
                    $nochesExtraDespues > 0 ? $nochesExtraDespues.' después' : null,
                ]);
            @endphp

            {{-- Pantalla 2 de 2: noches extra y resumen --}}
            <div class="mt-8 grid gap-5">
                <x-tarjeta titulo="Noches extra en Cusco">
                    <p class="-mt-2 text-[15px] text-texto-secundario">
                        Opcional. Máximo {{ $maximoNoches }} noches en total por reserva, para todo el grupo.
                    </p>

                    <div class="mt-4 divide-y divide-divisor">
                        @foreach ([
                            ['antes', 'Antes del recorrido', $nochesExtraAntes],
                            ['despues', 'Después (Machu Picchu)', $nochesExtraDespues],
                        ] as [$momento, $etiqueta, $cantidadNoches])
                            <div class="flex items-center justify-between gap-4 py-3">
                                <span id="noches-{{ $momento }}" class="text-[17px]">{{ $etiqueta }}</span>

                                {{-- Botones − y +: redondos, de 44 px para que se puedan tocar bien en el celular. --}}
                                <div class="flex items-center gap-3" role="group" aria-labelledby="noches-{{ $momento }}">
                                    <button type="button" wire:click="restarNoche('{{ $momento }}')" @disabled($cantidadNoches === 0)
                                            aria-label="Una noche menos"
                                            class="flex size-11 items-center justify-center rounded-full border border-accion text-enlace transition-colors
                                                   hover:bg-accion hover:text-white active:bg-accion-presionado
                                                   disabled:pointer-events-none disabled:border-divisor disabled:text-texto-secundario">
                                        <svg class="size-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.75"
                                             stroke-linecap="round" aria-hidden="true"><path d="M3.5 8h9" /></svg>
                                    </button>
                                    <span class="w-6 text-center text-xl font-semibold tabular-nums" aria-live="polite">{{ $cantidadNoches }}</span>
                                    <button type="button" wire:click="sumarNoche('{{ $momento }}')" aria-label="Una noche más"
                                            class="flex size-11 items-center justify-center rounded-full border border-accion text-enlace transition-colors
                                                   hover:bg-accion hover:text-white active:bg-accion-presionado">
                                        <svg class="size-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.75"
                                             stroke-linecap="round" aria-hidden="true"><path d="M3.5 8h9M8 3.5v9" /></svg>
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @error('nochesExtra')
                        <x-aviso tipo="error" class="mt-4">{{ $message }}</x-aviso>
                    @enderror
                </x-tarjeta>

                <x-tarjeta titulo="Tu selección">
                    <dl class="divide-y divide-divisor text-[17px]">
                        <div class="flex justify-between gap-4 py-3">
                            <dt class="text-texto-secundario">Salida</dt>
                            <dd class="text-right">
                                {{ $excursion->paquete->nombre }} ·
                                <span class="tabular-nums">{{ $excursion->getFechaSalida()->format('d/m/Y') }}</span>
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4 py-3">
                            <dt class="text-texto-secundario">Integrantes</dt>
                            <dd class="text-right">
                                <ul>
                                    @foreach ($integrantes as $integrante)
                                        <li>{{ $integrante['nombre'] }} {{ $integrante['apellido'] }}</li>
                                    @endforeach
                                </ul>
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4 py-3">
                            <dt class="text-texto-secundario">Llevan equipo de camping</dt>
                            <dd class="text-right tabular-nums">{{ $conEquipo === 0 ? 'Ninguno' : $conEquipo.' de '.count($integrantes) }}</dd>
                        </div>
                        <div class="flex justify-between gap-4 py-3">
                            <dt class="text-texto-secundario">Noches extra en Cusco</dt>
                            <dd class="text-right">{{ $noches === [] ? 'Ninguna' : implode(' y ', $noches) }}</dd>
                        </div>
                    </dl>
                </x-tarjeta>

                <x-aviso>Cuando confirmes, guardamos tus lugares durante {{ $plazoRetencion }} para que completes el pago.</x-aviso>

                <div class="mt-3 flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <x-boton variante="secundario" wire:click="cancelar">Cancelar</x-boton>

                    <div class="flex flex-col-reverse gap-3 sm:flex-row">
                        <x-boton variante="secundario" wire:click="volver">Volver</x-boton>
                        {{-- Mientras se procesa queda deshabilitado, para que no se confirme dos veces. --}}
                        <x-boton wire:click="confirmarReserva" wire:loading.attr="disabled" wire:target="confirmarReserva">
                            <span wire:loading.remove wire:target="confirmarReserva">Confirmar y pagar</span>
                            <span wire:loading wire:target="confirmarReserva">Guardando tus lugares…</span>
                        </x-boton>
                    </div>
                </div>
            </div>
        @endif
    @endif
</div>
