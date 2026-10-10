<?php

use App\Models\Reserva;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $correo = '';

    public string $numeroReserva = '';

    public bool $consultado = false;

    // Si el cliente ya consultó su reserva en esta sesión, la pantalla vuelve a mostrarla sin pedirle los datos.
    public function mount(): void
    {
        $acceso = session('acceso_reserva');

        if ($acceso) {
            $this->correo = $acceso['correo'];
            $this->numeroReserva = $acceso['numeroReserva'];
            $this->consultado = true;
        }
    }

    protected function rules(): array
    {
        return [
            'correo' => 'required|email',
            'numeroReserva' => ['required', 'regex:/^\d{6}-\d$/'],
        ];
    }

    protected function messages(): array
    {
        return [
            'correo.required' => 'Ingresá tu correo electrónico.',
            'correo.email' => 'El correo no tiene un formato válido.',
            'numeroReserva.required' => 'Ingresá el número de reserva.',
            'numeroReserva.regex' => 'El número de reserva no tiene un formato válido.',
        ];
    }

    public function consultarReserva(): void
    {
        $this->validate();

        $this->consultado = true;

        if ($this->reserva === null) {
            session()->forget('acceso_reserva');
            $this->addError('numeroReserva', 'No encontramos una reserva con esos datos.');

            return;
        }

        session(['acceso_reserva' => [
            'correo' => $this->correo,
            'numeroReserva' => $this->numeroReserva,
        ]]);
    }

    // Trae la reserva con todo lo que muestra la pantalla, para que la vista no haga consultas.
    #[Computed]
    public function reserva(): ?Reserva
    {
        if (! $this->consultado) {
            return null;
        }

        return Reserva::buscarPorCorreoYNumero($this->correo, $this->numeroReserva)?->getDetalle();
    }
};

?>

@use('App\Enums\EstadoReserva')

@php
    $reserva = $this->reserva;

    // Se piden una sola vez: el modelo consulta si la reserva ya tiene valoración.
    $opcionesHabilitadas = $reserva?->getOpcionesHabilitadas() ?? [];
    $saldoPorPagar = in_array(Reserva::OPCION_PAGAR_SALDO, $opcionesHabilitadas, true);

    // Qué se puede hacer desde Mi reserva y en qué estado se habilita cada opción (Reserva::getOpcionesHabilitadas).
    $opciones = [
        [Reserva::OPCION_PAGAR_SALDO, 'Pagá lo que falta de tu reserva.', [EstadoReserva::Confirmada], 'M3 6.5h14v8H3zM3 9.5h14M6 12.5h3'],
        [Reserva::OPCION_MODIFICAR, 'Cambiá tu reserva a otra fecha de salida, hasta tres meses antes de la salida actual.', [EstadoReserva::Confirmada], 'M12.5 4.5l3 3L8 15H5v-3zM10.5 6.5l3 3'],
        [Reserva::OPCION_CANCELAR, 'Antes de confirmar, te mostramos cuánto se te devuelve.', [EstadoReserva::Pendiente, EstadoReserva::Confirmada], 'M6 6l8 8M14 6l-8 8'],
        [Reserva::OPCION_REINTEGRO, 'Si no se consiguieron los permisos, te devolvemos lo que pagaste.', [EstadoReserva::SinPermiso], 'M4 10a6 6 0 1 0 2-4.5M4 4v3h3'],
        [Reserva::OPCION_REPROGRAMAR, 'Si no se consiguieron los permisos, elegí otra fecha de salida.', [EstadoReserva::SinPermiso], 'M4 5.5h12v10H4zM4 8.5h12M7 3.5v3M13 3.5v3'],
        [Reserva::OPCION_VALORAR, 'Cuando termina el viaje, contanos qué te parecieron los servicios.', [EstadoReserva::Finalizada], 'M10 3.5l1.9 4 4.1.5-3 2.9.8 4.1-3.8-2-3.8 2 .8-4.1-3-2.9 4.1-.5z'],
    ];
@endphp

<div>
    {{-- 1. Acceso: banda con el título y el buscador montado encima --}}
    <section class="relative overflow-hidden rounded-[28px] bg-accion px-6 pt-10 pb-40 text-white sm:px-10 sm:pt-14">
        {{-- Cordillera y sendero punteado, dibujados a mano: no hay fotos todavía. Los picos van a la derecha, lejos del texto. --}}
        <svg class="pointer-events-none absolute inset-x-0 bottom-16 h-36 w-full text-white" viewBox="0 0 1200 200"
             preserveAspectRatio="none" fill="none" stroke="currentColor" stroke-linejoin="round" aria-hidden="true">
            <path d="M0 178 C 160 170 300 168 420 152 S 560 132 620 110 L 700 64 760 100 840 22 920 88 990 54 1070 112 1130 80 1200 102"
                  stroke-opacity=".35" stroke-width="1.5" vector-effect="non-scaling-stroke" />
            <path d="M0 194 C 220 188 400 182 540 164 S 700 146 760 128 L 850 146 940 104 1030 150 1110 124 1200 140"
                  stroke-opacity=".2" stroke-width="1.5" vector-effect="non-scaling-stroke" />
            <path d="M0 200 C 280 194 460 182 600 166 S 800 132 920 124 1100 98 1200 90"
                  stroke-opacity=".7" stroke-width="2" stroke-linecap="round" stroke-dasharray="1 8" vector-effect="non-scaling-stroke" />
        </svg>

        <h1 class="relative text-4xl font-semibold sm:text-5xl">Mi reserva.</h1>
        <p class="relative mt-3 max-w-[46ch] text-[17px] leading-relaxed">
            Consultá el estado de tu viaje, pagá el saldo o gestioná tu reserva. Sólo necesitás tu correo y el número de reserva.
        </p>
    </section>

    <div class="relative -mt-20 px-3 sm:px-8">
        <x-tarjeta class="shadow-tarjeta-elevada">
            <form wire:submit="consultarReserva" class="grid gap-5 md:grid-cols-[1fr_1fr_auto]">
                <x-campo nombre="correo" etiqueta="Correo electrónico" type="email" wire:model="correo"
                         placeholder="nombre@correo.com" autocomplete="email" />
                <x-campo nombre="numeroReserva" etiqueta="Número de reserva" wire:model="numeroReserva"
                         placeholder="Ej.: 000124-7" />

                <div>
                    {{-- Ocupa el lugar de la etiqueta, para que el botón quede a la altura de los campos. --}}
                    <span class="hidden text-[15px] font-medium md:block" aria-hidden="true">&nbsp;</span>
                    <x-boton type="submit" class="min-h-14 w-full md:mt-2">Buscar mi reserva</x-boton>
                </div>
            </form>

            <p class="mt-5 flex items-start gap-2 border-t border-divisor pt-5 text-[15px] text-texto-secundario">
                <svg class="mt-0.5 size-4 shrink-0" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <path d="M3 5.5h14v9H3z" /><path d="m3 6 7 5 7-5" stroke-linejoin="round" />
                </svg>
                El número de reserva está en el correo de confirmación que te enviamos al reservar.
            </p>
        </x-tarjeta>
    </div>

    @if ($reserva)
        {{-- 2. Detalle y opciones de la reserva encontrada --}}
        <div class="mt-12 grid items-start gap-5 md:grid-cols-[1fr_320px]">
            <x-tarjeta>
                <div class="mb-2 flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-2xl font-semibold">Reserva {{ $reserva->numero_reserva }}</h2>
                    <x-chip-estado :estado="$reserva->estado" />
                </div>

                <dl class="divide-y divide-divisor text-[17px]">
                    <div class="flex justify-between gap-4 py-3"><dt class="text-texto-secundario">Paquete</dt><dd class="text-right">{{ $reserva->excursion->paquete->nombre }}</dd></div>
                    <div class="flex justify-between gap-4 py-3"><dt class="text-texto-secundario">Salida</dt><dd class="tabular-nums">{{ $reserva->excursion->getFechaSalida()->format('d/m/Y') }}</dd></div>
                </dl>

                <h3 class="mt-8 text-lg font-semibold">Integrantes</h3>
                <ul class="mt-1 divide-y divide-divisor text-[17px]">
                    @foreach ($reserva->excursionistas as $excursionista)
                        <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-3">
                            <span>{{ $excursionista->nombre }} {{ $excursionista->apellido }}</span>
                            <span class="flex items-center gap-2 text-[15px] text-texto-secundario">
                                Permiso
                                <x-chip-estado :estado="$excursionista->estado_permiso" />
                            </span>
                        </li>
                    @endforeach
                </ul>

                <h3 class="mt-8 text-lg font-semibold">Pagos</h3>
                <dl class="mt-1 divide-y divide-divisor text-[17px]">
                    <div class="flex justify-between gap-4 py-3"><dt class="text-texto-secundario">Monto total</dt><dd class="tabular-nums">USD {{ number_format($reserva->calcularMontoTotal(), 0, ',', '.') }}</dd></div>
                    <div class="flex justify-between gap-4 py-3">
                        <dt class="text-texto-secundario">Saldo</dt>
                        <dd class="text-right tabular-nums {{ $saldoPorPagar ? 'font-semibold text-ambar' : '' }}">
                            {{ $reserva->estado_saldo->value }} · USD {{ number_format($reserva->calcularSaldoPendiente(), 0, ',', '.') }}
                        </dd>
                    </div>
                </dl>

                <ul class="mt-2 grid gap-2">
                    @forelse ($reserva->pagos as $pago)
                        <li class="flex items-start justify-between gap-4 rounded-xl bg-fondo px-4 py-3">
                            <div>
                                <p class="font-medium">{{ $pago->tipo_pago->value }}</p>
                                <p class="text-[15px] text-texto-secundario">
                                    <span class="tabular-nums">{{ $pago->fecha->format('d/m/Y') }}</span> · {{ $pago->medio_pago }}
                                </p>
                            </div>
                            <p class="text-[17px] font-medium tabular-nums">USD {{ number_format($pago->monto, 0, ',', '.') }}</p>
                        </li>
                    @empty
                        <li class="rounded-xl bg-fondo px-4 py-3 text-[15px] text-texto-secundario">Todavía no hay pagos registrados.</li>
                    @endforelse
                </ul>
            </x-tarjeta>

            <x-tarjeta titulo="Qué podés hacer">
                <div class="grid gap-3">
                    @forelse ($opcionesHabilitadas as $opcion)
                        @if ($opcion === Reserva::OPCION_CANCELAR)
                            <x-boton variante="secundario" href="/mi-reserva/{{ $reserva->numero_reserva }}/cancelar">{{ $opcion }}</x-boton>
                        @elseif ($opcion === Reserva::OPCION_REINTEGRO)
                            <x-boton variante="secundario" href="/mi-reserva/{{ $reserva->numero_reserva }}/reintegro">{{ $opcion }}</x-boton>
                        @elseif ($opcion === Reserva::OPCION_MODIFICAR)
                            <x-boton variante="secundario" href="/mi-reserva/{{ $reserva->numero_reserva }}/modificar">{{ $opcion }}</x-boton>
                        @else
                            {{-- Pagar saldo, , reprogramar y valorar todavía no tienen pantalla. --}}
                            <x-boton disabled class="flex-col">
                                {{ $opcion }}
                                <span class="text-sm">Disponible próximamente</span>
                            </x-boton>
                        @endif
                    @empty
                        <p class="text-[15px] text-texto-secundario">
                            @if ($reserva->estado === EstadoReserva::Finalizada)
                                Ya valoraste los servicios de este viaje.
                            @elseif ($reserva->estado === EstadoReserva::Cancelada)
                                Esta reserva está cancelada y no tiene opciones de gestión.
                            @else
                                Una reserva «{{ $reserva->estado->value }}» no tiene opciones de gestión.
                            @endif
                        </p>
                    @endforelse
                </div>
            </x-tarjeta>
        </div>
    @else
        {{-- 3. Antes de buscar: lo que se puede hacer desde acá --}}
        <section aria-labelledby="que-podes-hacer" class="mt-20 sm:mt-24">
            <h2 id="que-podes-hacer" class="text-3xl font-semibold sm:text-4xl">
                Qué podés hacer.
                <span class="text-texto-secundario">Depende del estado de tu reserva.</span>
            </h2>

            {{-- El fondo de la lista es el divisor: se ve en el espacio de 1 px entre opciones y hace de línea. --}}
            <ul class="mt-8 grid gap-px overflow-hidden rounded-[18px] bg-divisor shadow-tarjeta sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($opciones as [$nombre, $descripcion, $estados, $icono])
                    <li class="flex gap-4 bg-tarjeta p-6 sm:p-7">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-aviso text-accion">
                            <svg class="size-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5"
                                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="{{ $icono }}" />
                            </svg>
                        </span>
                        <div>
                            <h3 class="text-[17px] font-semibold">{{ $nombre }}</h3>
                            <p class="mt-1 text-[15px] leading-relaxed text-texto-secundario">{{ $descripcion }}</p>
                            <p class="mt-3 flex flex-wrap gap-2">
                                @foreach ($estados as $estado)
                                    <x-chip-estado :estado="$estado" />
                                @endforeach
                            </p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
