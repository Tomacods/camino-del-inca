<?php

use App\Enums\EstadoReserva;
use App\Models\Reserva;
use App\Models\Valoracion;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Valorar servicios')] class extends Component
{
    #[Locked]
    public string $correo = '';

    #[Locked]
    public string $numeroReserva = '';

    #[Locked]
    public bool $valorada = false;

    // Un puntaje de 1 a 5 por categoría, indexado por el nombre del caso del enum (por ejemplo «CampingDeEtapa»).
    public array $puntajes = [];

    public string $comentario = '';

    // CU-23: se llega desde Consultar Reserva (CU-18), que deja en la sesión el correo con el que se consultó.
    public function mount(string $numeroReserva): void
    {
        $this->numeroReserva = $numeroReserva;
        $this->correo = session('acceso_reserva.correo', '');

        if ($this->reserva === null) {
            $this->redirect('/mi-reserva');
        }
    }

    protected function rules(): array
    {
        $reglas = [
            'comentario' => ['nullable', 'string', 'max:500'],
        ];

        foreach ($this->categorias as $categoria) {
            $reglas['puntajes.'.$categoria->name] = ['required', 'integer', 'between:'.Valoracion::PUNTAJE_MINIMO.','.Valoracion::PUNTAJE_MAXIMO];
        }

        return $reglas;
    }

    protected function messages(): array
    {
        return [
            'puntajes.*.required' => 'Elegí de 1 a 5 estrellas.',
            'puntajes.*.between' => 'El puntaje va de 1 a 5 estrellas.',
            'comentario.max' => 'El comentario puede tener hasta 500 caracteres.',
        ];
    }

    // Si falta algún puntaje, se pide completarlo. El modelo vuelve a controlar la reserva antes de guardar, por si se
    // valoró desde otra pestaña mientras tanto.
    public function enviar(): void
    {
        $this->validate();

        try {
            // Los botones de opción mandan el puntaje como texto: se pasa como número.
            Valoracion::valorarServicios(
                $this->reserva,
                array_map('intval', $this->puntajes),
                $this->comentario,
            );
        } catch (\DomainException $excepcion) {
            $this->addError('envio', $excepcion->getMessage());

            return;
        }

        $this->valorada = true;
    }

    #[Computed]
    public function reserva(): ?Reserva
    {
        if ($this->correo === '' || $this->numeroReserva === '') {
            return null;
        }

        return Reserva::buscarPorCorreoYNumero($this->correo, $this->numeroReserva);
    }

    #[Computed]
    public function categorias(): array
    {
        return $this->reserva ? Valoracion::getCategoriasAValorar($this->reserva) : [];
    }

    // R-23: el promedio que se va a guardar como experiencia general, para mostrarlo mientras el cliente elige las
    // estrellas. Hasta que estén todas las categorías, con un puntaje válido, no hay promedio (null).
    #[Computed]
    public function experienciaGeneral(): ?int
    {
        $puntajes = [];

        foreach ($this->categorias as $categoria) {
            $puntaje = (int) ($this->puntajes[$categoria->name] ?? 0);

            if ($puntaje < Valoracion::PUNTAJE_MINIMO || $puntaje > Valoracion::PUNTAJE_MAXIMO) {
                return null;
            }

            $puntajes[] = $puntaje;
        }

        return $puntajes === [] ? null : Valoracion::calcularExperienciaGeneral($puntajes);
    }
};

?>

<div class="mx-auto max-w-2xl">
    <h1 class="text-4xl font-semibold sm:text-5xl">
        Valorar servicios.
        <span class="text-texto-secundario">Contanos qué te pareció el viaje.</span>
    </h1>

    @if ($this->reserva)
        <p class="mt-3 text-[17px] text-texto-secundario">
            Reserva {{ $this->reserva->numero_reserva }} · {{ $this->reserva->excursion->paquete->nombre }}
            · salida del {{ $this->reserva->excursion->fecha_salida->format('d/m/Y') }}
        </p>

        <div class="mt-8 grid gap-5">
            @if ($valorada)
                <x-aviso titulo="¡Gracias por tu valoración!">
                    Ya quedó registrada. Tu experiencia general (promedio de los servicios) pasa a formar parte del
                    promedio del paquete.
                </x-aviso>

                <div>
                    <x-boton variante="secundario" href="/mi-reserva">Volver a mi reserva</x-boton>
                </div>
            @elseif (! Valoracion::admiteValoracion($this->reserva))
                {{-- La reserva todavía no terminó o ya se valoró: se informa y no se muestra el formulario. --}}
                <x-aviso tipo="error" titulo="No se puede valorar esta reserva">
                    @if ($this->reserva->tieneValoracion())
                        Ya valoraste los servicios de este viaje. ¡Gracias por contarnos!
                    @else
                        Se valoran los servicios cuando el viaje terminó y la reserva está «{{ EstadoReserva::Finalizada->value }}».
                    @endif
                </x-aviso>

                <div>
                    <x-boton variante="secundario" href="/mi-reserva">Volver a mi reserva</x-boton>
                </div>
            @else
                @error('envio')
                    <x-aviso tipo="error" titulo="No se registró la valoración">{{ $message }}</x-aviso>
                @enderror

                <form wire:submit="enviar" class="grid gap-5">
                    <x-tarjeta titulo="Servicios">
                        <p class="text-[15px] text-texto-secundario">De 1 a 5 estrellas, cada uno por separado.</p>

                        <div class="mt-4 divide-y divide-divisor">
                            @foreach ($this->categorias as $categoria)
                                <x-estrellas :modelo="'puntajes.'.$categoria->name" :etiqueta="$categoria->value"
                                             :valor="$puntajes[$categoria->name] ?? null" />
                            @endforeach
                        </div>
                    </x-tarjeta>

                    <x-tarjeta titulo="Experiencia general (promedio)">
                        {{-- No se elige: es el promedio de los servicios, redondeado, y se actualiza con cada estrella. --}}
                        <p class="text-[15px] text-texto-secundario">Se calcula sola: es el promedio de los puntajes de los servicios.</p>

                        <p class="mt-3 text-[17px] text-texto" aria-live="polite">
                            @if ($this->experienciaGeneral === null)
                                Se calcula cuando valores todos los servicios.
                            @else
                                <span role="img" aria-label="{{ $this->experienciaGeneral }} de 5 estrellas" class="text-xl leading-none tracking-wider">
                                    <span class="text-ambar">{{ str_repeat('★', $this->experienciaGeneral) }}</span><span class="text-divisor">{{ str_repeat('★', 5 - $this->experienciaGeneral) }}</span>
                                </span>
                                <span class="ml-2 font-semibold tabular-nums">{{ $this->experienciaGeneral }}</span> de 5
                            @endif
                        </p>
                    </x-tarjeta>

                    <x-tarjeta titulo="Tu comentario">
                        <label for="comentario" class="block text-[15px] font-medium text-texto">Comentario (opcional)</label>
                        <textarea id="comentario" wire:model="comentario" rows="4" maxlength="500"
                                  @error('comentario') aria-invalid="true" aria-describedby="comentario-error" @enderror
                                  class="mt-2 block w-full rounded-xl border border-borde-campo bg-tarjeta px-4 py-3 text-[17px] text-texto
                                         placeholder:text-texto-secundario transition-[border-color,box-shadow] duration-150 ease-out
                                         focus:border-accion focus:shadow-[0_0_0_4px_rgb(0_113_227/0.25)] focus:outline-hidden"
                                  placeholder="Lo que más te gustó, o lo que podríamos mejorar."></textarea>
                        @error('comentario')
                            <p id="comentario-error" class="mt-2 text-sm text-error">{{ $message }}</p>
                        @enderror
                    </x-tarjeta>

                    <div class="flex flex-wrap justify-between gap-3">
                        <x-boton variante="secundario" href="/mi-reserva">Volver a mi reserva</x-boton>
                        <x-boton type="submit">Enviar valoración</x-boton>
                    </div>
                </form>
            @endif
        </div>
    @endif
</div>
