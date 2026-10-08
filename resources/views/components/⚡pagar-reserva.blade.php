<?php

use App\Jobs\LiberarCupoRetenido;
use App\Models\Excursion;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

// Primera pantalla de Pagar Reserva (CU-15). Por ahora tiene sólo lo que CU-14 necesita para terminar: mostrar la
// retención, cancelarla (A6) y liberarla si se vence. El pago se completa con CU-15.
new #[Title('Pagar reserva')] class extends Component
{
    // Qué muestra la pantalla: 'vigente', 'vencida', 'ya-no-en-curso' (la retención que mostraba ya no es la de la
    // sesión) o 'sin-reserva' (se entró sin nada en la sesión). Lo decide el servidor.
    #[Locked]
    public string $estado = 'sin-reserva';

    // La retención que mostraba la pantalla al abrirse. El cliente puede haber confirmado otra en otra pestaña: la
    // pantalla sólo actúa sobre ésta.
    #[Locked]
    public ?string $idRetencion = null;

    // Cuando la retención vence, la dirección para volver a reservar la misma salida.
    #[Locked]
    public ?string $direccionVolverAReservar = null;

    public function mount(): void
    {
        $this->idRetencion = session('reserva_en_curso.id_retencion');

        $this->comprobarVencimiento();
    }

    // Quien decide si venció es el servidor, comparando su hora con «vence», nunca el reloj del navegador. Devuelve los
    // segundos que faltan, para que el contador de la página siga desde ahí.
    public function comprobarVencimiento(): int
    {
        $enCurso = $this->reservaEnCurso;

        if ($this->estado === 'vencida') {
            return 0;
        }

        // Si la sesión ya no tiene la retención de esta pantalla, no se libera nada ni se borra la sesión.
        if ($enCurso === null) {
            $this->estado = $this->idRetencion === null ? 'sin-reserva' : 'ya-no-en-curso';

            return 0;
        }

        if ($this->segundosRestantes > 0) {
            $this->estado = 'vigente';

            return $this->segundosRestantes;
        }

        $excursion = $this->excursion;
        $this->direccionVolverAReservar = '/reservar/'.$excursion->id_paquete.'/'.$excursion->getFechaSalida()->format('Y-m-d');
        $this->liberarReservaEnCurso($enCurso);
        $this->estado = 'vencida';

        return 0;
    }

    // A6: el cliente cancela. Los lugares se liberan en el momento, sólo si la retención de esta pantalla sigue siendo
    // la de la sesión; si no, la pantalla sólo avisa que ya no está en curso.
    public function cancelar(): void
    {
        if ($this->reservaEnCurso === null) {
            $this->comprobarVencimiento();

            return;
        }

        $this->liberarReservaEnCurso($this->reservaEnCurso);

        $this->redirect('/');
    }

    // La reserva en curso de la sesión, sólo si es la misma que mostraba esta pantalla al abrirse.
    #[Computed]
    public function reservaEnCurso(): ?array
    {
        $enCurso = session('reserva_en_curso');

        return $enCurso !== null && $enCurso['id_retencion'] === $this->idRetencion ? $enCurso : null;
    }

    #[Computed]
    public function excursion(): ?Excursion
    {
        return $this->reservaEnCurso === null ? null : Excursion::with('paquete')->find($this->reservaEnCurso['id_excursion']);
    }

    // Los segundos que faltan para que venza la retención, con la hora del servidor.
    #[Computed]
    public function segundosRestantes(): int
    {
        if ($this->reservaEnCurso === null) {
            return 0;
        }

        $vence = Carbon::parse($this->reservaEnCurso['vence']);

        return now()->lessThan($vence) ? (int) ceil(now()->diffInSeconds($vence)) : 0;
    }

    // Se libera con la misma tarea que la demorada: si esa ya corrió, ésta no descuenta de nuevo.
    private function liberarReservaEnCurso(array $enCurso): void
    {
        LiberarCupoRetenido::dispatchSync($enCurso['id_excursion'], count($enCurso['integrantes']), $enCurso['id_retencion']);

        session()->forget('reserva_en_curso');
    }
};

?>

@php
    $minutosRetencion = config('reserva.minutos_retencion');
    $plazoRetencion = $minutosRetencion === 1 ? '1 minuto' : $minutosRetencion.' minutos';
@endphp

<div class="mx-auto max-w-3xl">
    @if ($estado === 'vigente')
        @php
            $enCurso = $this->reservaEnCurso;
            $excursion = $this->excursion;
            $segundos = $this->segundosRestantes;
            $conEquipo = count(array_filter(array_column($enCurso['integrantes'], 'equipo_camping')));
            $noches = array_filter([
                $enCurso['noches_extra_antes'] > 0 ? $enCurso['noches_extra_antes'].' antes del recorrido' : null,
                $enCurso['noches_extra_despues'] > 0 ? $enCurso['noches_extra_despues'].' después' : null,
            ]);
        @endphp

        <header class="flex flex-wrap items-end justify-between gap-4">
            <h1 class="text-4xl font-semibold sm:text-5xl">Pago de la reserva</h1>

            {{--
                El contador baja en el navegador, desde los segundos que informó el servidor. Al llegar a cero le pregunta
                al servidor, que es quien decide si venció; si todavía falta, sigue con el tiempo que le contesta.
                role="timer" hace que el lector de pantalla no lo anuncie cada segundo; se lee cuando se llega a él.
                wire:ignore: Livewire no lo vuelve a dibujar y el contador no se reinicia. En el último minuto pasa a ámbar.
            --}}
            <p role="timer" aria-live="off" wire:ignore
               x-data="{
                   fin: Date.now() + {{ $segundos }} * 1000,
                   restantes: {{ $segundos }},
                   consultando: false,
                   init() { this.intervalo = setInterval(() => this.descontar(), 1000) },
                   destroy() { clearInterval(this.intervalo) },
                   async descontar() {
                       this.restantes = Math.max(0, Math.ceil((this.fin - Date.now()) / 1000));
                       if (this.restantes > 0 || this.consultando) return;
                       this.consultando = true;
                       try {
                           const segundos = await this.$wire.comprobarVencimiento();
                           this.fin = Date.now() + segundos * 1000;
                           this.restantes = segundos;
                       } finally {
                           this.consultando = false;
                       }
                   },
                   get reloj() {
                       return String(Math.floor(this.restantes / 60)).padStart(2, '0') + ':' + String(this.restantes % 60).padStart(2, '0');
                   },
               }"
               :class="{ 'bg-ambar-fondo text-ambar': restantes <= 60, 'bg-aviso text-enlace': restantes > 60 }"
               class="rounded-full px-4 py-2 text-[17px] font-semibold transition-colors
                      {{ $segundos <= 60 ? 'bg-ambar-fondo text-ambar' : 'bg-aviso text-enlace' }}">
                Retención: <span class="tabular-nums" x-text="reloj">{{ sprintf('%02d:%02d', intdiv($segundos, 60), $segundos % 60) }}</span>
            </p>
        </header>

        <x-aviso titulo="Tus lugares están guardados" class="mt-8">
            Tenés {{ $plazoRetencion }} para completar el pago. Si el plazo se vence, los lugares vuelven a estar disponibles.
        </x-aviso>

        <x-tarjeta titulo="Tu reserva" class="mt-5">
            <dl class="divide-y divide-divisor text-[17px]">
                <div class="flex justify-between gap-4 py-3">
                    <dt class="text-texto-secundario">Paquete</dt>
                    <dd class="text-right">{{ $excursion->paquete->nombre }}</dd>
                </div>
                <div class="flex justify-between gap-4 py-3">
                    <dt class="text-texto-secundario">Salida</dt>
                    <dd class="tabular-nums">{{ $excursion->getFechaSalida()->format('d/m/Y') }}</dd>
                </div>
                <div class="flex justify-between gap-4 py-3">
                    <dt class="text-texto-secundario">Integrantes</dt>
                    <dd class="text-right">
                        <ul>
                            @foreach ($enCurso['integrantes'] as $integrante)
                                <li>{{ $integrante['nombre'] }} {{ $integrante['apellido'] }}</li>
                            @endforeach
                        </ul>
                    </dd>
                </div>
                <div class="flex justify-between gap-4 py-3">
                    <dt class="text-texto-secundario">Llevan equipo de camping</dt>
                    <dd class="text-right tabular-nums">{{ $conEquipo === 0 ? 'Ninguno' : $conEquipo.' de '.count($enCurso['integrantes']) }}</dd>
                </div>
                <div class="flex justify-between gap-4 py-3">
                    <dt class="text-texto-secundario">Noches extra en Cusco</dt>
                    <dd class="text-right">{{ $noches === [] ? 'Ninguna' : implode(' y ', $noches) }}</dd>
                </div>
            </dl>
        </x-tarjeta>

        <div class="mt-8 flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
            <x-boton variante="secundario" wire:click="cancelar">Cancelar</x-boton>
            {{-- Se conecta con CU-15. --}}
            <x-boton disabled class="flex-col">
                Pagar con Mercado Pago
                <span class="text-sm">Disponible próximamente</span>
            </x-boton>
        </div>
    @elseif ($estado === 'vencida')
        <h1 class="text-4xl font-semibold sm:text-5xl">Pago de la reserva</h1>

        <x-aviso tipo="error" titulo="Se venció el plazo" class="mt-8">
            {{ $minutosRetencion === 1 ? 'Pasó 1 minuto' : 'Pasaron los '.$minutosRetencion.' minutos' }} sin que se registrara el pago y tus lugares se liberaron. Podés volver a reservar.
        </x-aviso>

        <x-boton :href="$direccionVolverAReservar" class="mt-6">Volver a reservar</x-boton>
    @elseif ($estado === 'ya-no-en-curso')
        {{-- Se canceló o se reemplazó desde otra pestaña: el cliente puede tener otra reserva en curso. --}}
        <h1 class="text-4xl font-semibold sm:text-5xl">Pago de la reserva</h1>

        <x-aviso class="mt-8">Esta reserva ya no está en curso.</x-aviso>

        <x-boton href="/paquetes" class="mt-6">Ver los paquetes</x-boton>
    @else
        <h1 class="text-4xl font-semibold sm:text-5xl">Pago de la reserva</h1>

        <x-aviso class="mt-8">No tenés una reserva en curso.</x-aviso>

        <x-boton href="/paquetes" class="mt-6">Ver los paquetes</x-boton>
    @endif
</div>
