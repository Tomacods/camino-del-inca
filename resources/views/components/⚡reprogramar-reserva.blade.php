<?php

use App\Models\Reserva;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public string $correo = '';

    #[Locked]
    public string $numeroReserva = '';

    #[Locked]
    public int $paso = 1;

    #[Locked]
    public string $fechaDestino = '';

    #[Locked]
    public string $mensajeError = '';

    public function mount(string $numeroReserva): void
    {
        $this->numeroReserva = $numeroReserva;
        $this->correo = session('acceso_reserva.correo', '');

        if ($this->reserva === null) {
            $this->redirect('/mi-reserva');
            return;
        }

        $this->buscar();
    }

    public function buscar(): void
    {
        $this->validate([
            'correo' => 'required|email',
            'numeroReserva' => ['required', 'regex:/^\d{6}-\d$/'],
        ], [
            'correo.required' => 'Ingresá tu correo electrónico.',
            'correo.email' => 'El correo no tiene un formato válido.',
            'numeroReserva.required' => 'Ingresá el número de reserva.',
            'numeroReserva.regex' => 'El número de reserva no tiene un formato válido.',
        ]);

        $this->mensajeError = '';

        if ($this->reserva === null) {
            $this->addError('numeroReserva', 'No encontramos una reserva con esos datos.');
            return;
        }

        try {
            $this->reserva->iniciarReprogramacion();
            $this->paso = 2;
        } catch (\DomainException $e) {
            $this->mensajeError = $e->getMessage();   // paso 1: no hay lista a la cual volver
        }
    }

    public function elegir(string $fecha): void
    {
        $this->mensajeError = '';

        // La fecha llega del navegador: si no es una fecha aaaa-mm-dd válida, Carbon::parse lanzaría una excepción que no
        // es DomainException (error 500). Se avisa como una excursión que ya no está disponible.
        if (Validator::make(['fecha' => $fecha], ['fecha' => 'date_format:Y-m-d'])->fails()) {
            $this->mostrarError(new \DomainException('La excursión elegida ya no está disponible.'));
            return;
        }

        try {
            $this->reserva->elegirExcursionDestinoRepro(Carbon::parse($fecha));
            $this->fechaDestino = $fecha;
            $this->paso = 3;
        } catch (\DomainException $e) {
            $this->mostrarError($e);
        }
    }

    private function mostrarError(\DomainException $e): void
    {
        unset($this->reserva);   // descarta la reserva cacheada y la vuelve a leer
        $this->mensajeError = $e->getMessage();

        try {
            $this->reserva->iniciarReprogramacion();
            $this->paso = 2;      // sigue admitiendo cambios: vuelve a la lista actualizada
        } catch (\DomainException) {
            $this->paso = 1;      // ya no admite cambios: sin lista, solo el aviso
        }
    }

    public function confirmar(): void
    {
        $this->mensajeError = '';

        try {
            $cambio = $this->reserva->elegirExcursionDestinoRepro(Carbon::parse($this->fechaDestino));
            $this->reserva->reprogramar($cambio['destino']);
            $this->paso = 4;
        } catch (\DomainException $e) {
            $this->mostrarError($e);
        }
    }

    public function volverALista(): void
    {
        $this->mensajeError = '';
        $this->fechaDestino = '';

        try {
            $this->reserva->iniciarReprogramacion();
            $this->paso = 2;
        } catch (\DomainException $e) {
            $this->mostrarError($e);
        }
    }

    public function cancelarOperacion()
    {
        return $this->redirect('/mi-reserva');
    }

    #[Computed]
    public function reserva(): ?Reserva
    {
        if (empty($this->correo) || empty($this->numeroReserva)) {
            return null;
        }

        return Reserva::buscarPorCorreoYNumero($this->correo, $this->numeroReserva);
    }

    #[Computed]
    public function habilitadas()
    {
        return $this->reserva->iniciarReprogramacion();
    }

    #[Computed]
    public function resumen(): array
    {
        return $this->reserva->elegirExcursionDestinoRepro(Carbon::parse($this->fechaDestino));
    }
};
?>
<div>
    <h1 class="text-3xl font-semibold sm:text-4xl">
        Reprogramar la reserva. <span class="text-texto-secundario">Elegí otra fecha de salida.</span>
    </h1>

    <div class="mt-8 grid items-start gap-5 md:grid-cols-[320px_1fr]">
        {{-- COLUMNA IZQUIERDA: reserva actual --}}
        @if ($this->reserva)
            <x-tarjeta>
                <div class="mb-2 flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-2xl font-semibold">Reserva {{ $this->reserva->numero_reserva }}</h2>
                    <x-chip-estado :estado="$this->reserva->estado" />
                </div>

                <dl class="divide-y divide-divisor text-[17px]">
                    <div class="flex justify-between gap-4 py-3"><dt class="text-texto-secundario">Paquete</dt><dd class="text-right">{{ $this->reserva->excursion->paquete->nombre }}</dd></div>
                    <div class="flex justify-between gap-4 py-3"><dt class="text-texto-secundario">Salida actual</dt><dd class="tabular-nums">{{ $this->reserva->excursion->getFechaSalida()->format('d/m/Y') }}</dd></div>
                    <div class="flex justify-between gap-4 py-3"><dt class="text-texto-secundario">Integrantes</dt><dd class="tabular-nums">{{ $this->reserva->excursionistas->count() }}</dd></div>
                </dl>
            </x-tarjeta>
        @endif

        {{-- COLUMNA DERECHA: pasos --}}
        <div class="grid gap-5">
            @if ($mensajeError)
                <x-aviso tipo="error" titulo="No se puede reprogramar">
                    {{ $mensajeError }}
                </x-aviso>

                @if ($paso === 1)
                    <div>
                        <x-boton variante="secundario" wire:click="cancelarOperacion">Volver a mi reserva</x-boton>
                    </div>
                @endif
            @endif

            {{-- PASO 2: excursiones habilitadas --}}
            @if ($paso === 2)
                <x-tarjeta>
                    @if ($this->habilitadas->isNotEmpty())
                        <h2 class="text-2xl font-semibold">Elegí la nueva salida</h2>

                        <ul class="mt-2 divide-y divide-divisor">
                            @foreach ($this->habilitadas as $excursion)
                                <li class="flex flex-wrap items-center justify-between gap-3 py-4">
                                    <div>
                                        <p class="text-[17px] font-medium tabular-nums">{{ $excursion->fecha_salida->format('d/m/Y') }}</p>
                                        <p class="text-[15px] text-texto-secundario">
                                            {{ $excursion->cupo_disponible }} lugares · Guía {{ $excursion->guia->nombre }} {{ $excursion->guia->apellido }}
                                        </p>
                                    </div>
                                    <x-boton wire:click="elegir('{{ $excursion->fecha_salida->toDateString() }}')">Elegir</x-boton>
                                </li>
                            @endforeach
                        </ul>

                        <x-boton variante="secundario" wire:click="cancelarOperacion" class="mt-4">Volver</x-boton>
                    @else
                        <x-aviso titulo="No hay otras salidas disponibles">
                            No hay otras salidas de este paquete con lugar para todo el grupo y con la anticipación mínima. Podés solicitar el reintegro del 100 % de lo abonado.
                        </x-aviso>

                        <div class="mt-4 flex flex-wrap gap-3">
                            <x-boton href="/mi-reserva/{{ $this->reserva->numero_reserva }}/reintegro">Solicitar reintegro</x-boton>
                            <x-boton variante="secundario" wire:click="cancelarOperacion">Volver</x-boton>
                        </div>
                    @endif
                </x-tarjeta>
            @endif

            {{-- PASO 3: resumen del cambio --}}
            @if ($paso === 3)
                @php($cambio = $this->resumen)
                <x-tarjeta titulo="Resumen del cambio">
                    <dl class="divide-y divide-divisor text-[17px]">
                        <div class="flex justify-between gap-4 py-3">
                            <dt class="text-texto-secundario">Salida</dt>
                            <dd class="tabular-nums">{{ $cambio['origen']->fecha_salida->format('d/m/Y') }} → <span class="font-semibold">{{ $cambio['destino']->fecha_salida->format('d/m/Y') }}</span></dd>
                        </div>
                        <div class="flex justify-between gap-4 py-3">
                            <dt class="text-texto-secundario">Guía</dt>
                            <dd>{{ $cambio['destino']->guia->nombre }} {{ $cambio['destino']->guia->apellido }}</dd>
                        </div>
                        <div class="flex justify-between gap-4 py-3">
                            <dt class="text-texto-secundario">Nueva fecha límite de confirmación</dt>
                            <dd class="tabular-nums">{{ $cambio['fecha_limite']->format('d/m/Y') }}</dd>
                        </div>
                        @if ($cambio['fecha_limite_saldo'])
                            <div class="flex justify-between gap-4 py-3">
                                <dt class="text-texto-secundario">Nueva fecha límite de pago del saldo</dt>
                                <dd class="tabular-nums">{{ $cambio['fecha_limite_saldo']->format('d/m/Y') }}</dd>
                            </div>
                        @endif
                    </dl>

                    <x-aviso class="mt-5">
                        La reserva vuelve a «Pendiente» y los permisos de los {{ $this->reserva->excursionistas->count() }} integrantes se validan de nuevo.
                    </x-aviso>

                    <div class="mt-5 flex flex-wrap gap-3">
                        <x-boton wire:click="confirmar">Confirmar cambio</x-boton>
                        <x-boton variante="secundario" wire:click="volverALista">Volver</x-boton>
                    </div>
                </x-tarjeta>
            @endif

            {{-- PASO 4: éxito --}}
            @if ($paso === 4)
                <x-aviso titulo="¡Reserva reprogramada!">
                    La reserva fue reprogramada y queda pendiente de validación de permisos.
                </x-aviso>

                <div>
                    <x-boton wire:click="cancelarOperacion">Volver a mi reserva</x-boton>
                </div>
            @endif
        </div>
    </div>
</div>
