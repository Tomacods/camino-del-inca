{{-- Muestrario de los componentes del portal. Sólo existe en el entorno local (ver routes/web.php). --}}
<x-layouts::app title="Guía visual">
    <h1 class="text-4xl font-bold tracking-tight">Guía visual del portal</h1>
    <p class="mt-3 max-w-[65ch] text-texto-secundario">
        Los componentes de <code>resources/views/components</code>. El detalle de uso está en
        <code>docs/guia-visual-portal.md</code>.
    </p>

    <div class="mt-12 space-y-12">
        <section>
            <h2 class="mb-4 text-2xl font-semibold">Botones</h2>
            <div class="flex flex-wrap gap-3">
                <x-boton>Confirmar reserva</x-boton>
                <x-boton variante="secundario">Volver</x-boton>
                <x-boton disabled>Deshabilitado</x-boton>
                <x-boton href="/mi-reserva" variante="secundario">Enlace como botón</x-boton>
            </div>
        </section>

        <section>
            <h2 class="mb-4 text-2xl font-semibold">Campos</h2>
            <x-tarjeta class="grid max-w-xl gap-6">
                <x-campo nombre="ejemplo-normal" etiqueta="Correo electrónico" type="email" placeholder="nombre@correo.com" />
                <x-campo nombre="ejemplo-valido" etiqueta="Número de reserva" value="000124-7" :valido="true" />
                <x-campo nombre="ejemplo-error" etiqueta="Correo electrónico" type="email" value="nombre@correo" />
                <x-campo nombre="ejemplo-ayuda" etiqueta="Número de reserva" placeholder="000124-7"
                         ayuda="Está en el correo de confirmación que te enviamos al reservar." />
            </x-tarjeta>
        </section>

        <section>
            <h2 class="mb-4 text-2xl font-semibold">Avisos</h2>
            <div class="grid max-w-xl gap-4">
                <x-aviso titulo="Tu lugar está reservado">Tenés 5 minutos para completar el pago.</x-aviso>
                <x-aviso>Te enviamos el número de reserva por correo.</x-aviso>
                <x-aviso tipo="error" titulo="No pudimos registrar el pago">Probá de nuevo en unos minutos.</x-aviso>
            </div>
        </section>

        <section>
            <h2 class="mb-4 text-2xl font-semibold">Tarjeta</h2>
            <x-tarjeta titulo="Reserva 000124-7" class="max-w-xl">
                <dl class="divide-y divide-divisor">
                    <div class="flex justify-between gap-4 py-3"><dt class="text-texto-secundario">Paquete</dt><dd>Paquete de ejemplo</dd></div>
                    <div class="flex justify-between gap-4 py-3"><dt class="text-texto-secundario">Salida</dt><dd class="tabular-nums">14/11/2026</dd></div>
                    <div class="flex justify-between gap-4 py-3"><dt class="text-texto-secundario">Saldo</dt><dd class="text-ambar tabular-nums">USD 450</dd></div>
                </dl>
            </x-tarjeta>
            <p class="mt-2 text-sm text-texto-secundario">Datos de ejemplo.</p>
        </section>

        <section>
            <h2 class="mb-4 text-2xl font-semibold">Estados de la reserva</h2>
            <div class="flex flex-wrap gap-3">
                @foreach (App\Enums\EstadoReserva::cases() as $estado)
                    <x-chip-estado :estado="$estado" />
                @endforeach
            </div>
        </section>
    </div>
</x-layouts::app>
