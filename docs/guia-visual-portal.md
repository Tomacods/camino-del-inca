# Guía visual del portal

Cómo se ven las pantallas públicas del cliente: la paleta, la tipografía, el layout y los componentes Blade
reutilizables. La estética es clara y toma como referencia la tienda de Apple (apple.com/store): fondo gris muy claro,
tarjetas blancas con sombra suave, botones azules en forma de pastilla y títulos grandes en dos tonos. Es el diseño
vigente desde el 06/10/2026; la paleta oscura del prototipo quedó reservada (ver
[Paleta reservada para un posible modo oscuro](#paleta-reservada-para-un-posible-modo-oscuro)).

Para ver todos los componentes juntos, con el servidor local levantado: <http://localhost:8000/guia-visual>. Esa
página sólo existe con `APP_ENV=local`.

## Paleta

Los colores están definidos como variables en `resources/css/app.css` (bloque `@theme`) y se usan con las clases de
Tailwind: `bg-<nombre>`, `text-<nombre>`, `border-<nombre>`. No se escriben colores sueltos en las vistas.

| Variable | Valor | Para qué se usa | Ejemplo de clase |
|---|---|---|---|
| `fondo` | `#F5F5F7` | Fondo de la página | `bg-fondo` |
| `tarjeta` | `#FFFFFF` | Tarjetas, campos y encabezado | `bg-tarjeta` |
| `divisor` | `#D2D2D7` | Líneas que separan filas y secciones | `border-divisor`, `divide-divisor` |
| `borde-campo` | `#86868B` | Borde de los campos de texto | `border-borde-campo` |
| `accion` | `#0071E3` | Botón primario (con texto blanco), foco | `bg-accion text-white` |
| `accion-hover` | `#0077ED` | Botón al pasar el mouse | `hover:bg-accion-hover` |
| `accion-presionado` | `#006EDB` | Botón mientras se presiona | `active:bg-accion-presionado` |
| `enlace` | `#0066CC` | Enlaces y texto del botón secundario | `text-enlace` |
| `aviso` | `#E8F2FD` | Fondo de los avisos informativos y de los estados Confirmada y Obtenido | `bg-aviso` |
| `texto` | `#1D1D1F` | Texto principal | `text-texto` |
| `texto-secundario` | `#6E6E73` | Descripciones, etiquetas de datos, segunda parte de los títulos | `text-texto-secundario` |
| `exito` | `#00802F` | Borde y tilde del campo válido | `border-exito` |
| `error` | `#D70015` | Mensajes y bordes de error; estado Sin Permiso | `text-error` |
| `error-fondo` | `#FFF2F4` | Fondo del aviso de error y del campo con error | `bg-error-fondo` |
| `ambar` | `#9A5B00` | Texto del estado Pendiente y del saldo adeudado | `text-ambar` |
| `ambar-fondo` | `#FDF3DC` | Fondo del estado Pendiente | `bg-ambar-fondo` |
| `gris-fondo` | `#E8E8ED` | Estado Cancelada y botón deshabilitado | `bg-gris-fondo` |

Reglas:

- Sobre `accion` el texto va **blanco** (`text-white`). Los colores de estado se usan de a pares: fondo claro y texto
  oscuro del mismo tono (`bg-ambar-fondo text-ambar`), así se leen bien.
- Las tarjetas usan la sombra `shadow-tarjeta` y bordes redondeados de 18 px (`rounded-[18px]`).
- Para montos y fechas en columnas se agrega `tabular-nums`, así los números quedan alineados.
- `borde`, `inca` y `peligro` son los nombres anteriores. Siguen funcionando (apuntan a `divisor`, `accion` y `error`)
  para no romper las pantallas de Mi reserva; cuando esas pantallas pasen a los componentes, se quitan de `app.css`.

## Tipografía

Una sola familia, **Inter**, para títulos y textos (es la de Google Fonts más parecida a la de Apple). Se carga en el
layout con los pesos 400, 500, 600 y 700.

- Títulos en `font-semibold`, con el espaciado entre letras un poco cerrado (ya aplicado a `h1`, `h2` y `h3`).
- Títulos en dos tonos, como en la tienda de Apple: la primera frase en `texto` y la segunda en `texto-secundario`.

  ```blade
  <h1 class="text-5xl font-semibold">
      Paquetes. <span class="text-texto-secundario">Elegí el que más te guste.</span>
  </h1>
  ```

Tamaños de referencia: título de página `text-5xl` (en la portada llega a `lg:text-7xl`), título de sección
`text-3xl`/`sm:text-4xl`, título de tarjeta `text-2xl`, texto `text-[17px]` y ayudas `text-sm`. El texto nunca baja
de `text-sm`.

## Paleta reservada para un posible modo oscuro

La paleta del prototipo aprobado original queda guardada para un posible modo oscuro. **No se implementa por ahora**:
no hay modo oscuro en el portal y las vistas no llevan variantes `dark:`. Si algún día se hace, estos valores serían
el punto de partida.

| Rol | Valor |
|---|---|
| Fondo | `#000000` (negro) |
| Tarjetas | `#221F1C` |
| Divisores | `#242424` |
| Acción principal | `#00F5D0` (turquesa), con texto negro |
| Acción · hover | `#00A39E` |
| Acción · presionado | `#007A76` |
| Avisos (fondo) | `#0E2A28` |
| Texto | `#F5F5F5` |
| Texto secundario | `#B8B8B8` |
| Error | `#FF6B6B` |
| Ámbar | `#F5C451` |

Tipografías de esa paleta: **Titillium Web** para los títulos y **DM Sans** para los textos.

## Layout

`resources/views/layouts/app.blade.php`: encabezado con «Camino del Inca» y el menú (Paquetes, Mi reserva), el
contenido y el pie. El encabezado queda fijo arriba, translúcido, y el enlace de la sección actual se marca en negrita.

**Componentes Livewire de página completa:** lo usan solos, porque es el layout por defecto de Livewire
(`layouts::app`). Para el título de la pestaña:

```php
use Livewire\Attributes\Title;

new #[Title('Mi reserva')] class extends Component
{
    // ...
};
```

**Vistas Blade comunes:**

```blade
<x-layouts::app title="Paquetes">
    <h1 class="text-4xl font-bold">Paquetes</h1>
    ...
</x-layouts::app>
```

La pestaña muestra «Paquetes · Camino del Inca»; sin título, sólo «Camino del Inca».

## Componentes

Están en `resources/views/components`. Todos aceptan atributos extra (`class`, `wire:click`, `wire:model`, etc.), que
se suman a los del componente.

### Botón — `<x-boton>`

| Atributo | Valores | Por defecto |
|---|---|---|
| `variante` | `primario` (azul lleno), `secundario` (contorno azul) | `primario` |
| `type` | `button`, `submit` | `button` |
| `href` | una dirección: se dibuja como enlace | — |

Uno solo primario por pantalla: la acción principal. Lo demás (volver, cancelar, ver otra cosa), secundario. Mientras
Livewire procesa la acción que disparó, el botón se atenúa; con `disabled` queda gris.

```blade
<x-boton type="submit">Confirmar reserva</x-boton>
<x-boton variante="secundario" href="/mi-reserva">Volver a mi reserva</x-boton>
<x-boton wire:click="pagar">Pagar el saldo</x-boton>
```

Para una opción que todavía no tiene pantalla, botón deshabilitado con la leyenda abajo (así se usa en Mi reserva):

```blade
<x-boton disabled class="flex-col">
    Pagar saldo
    <span class="text-sm">Disponible próximamente</span>
</x-boton>
```

### Campo de texto — `<x-campo>`

| Atributo | Para qué | Obligatorio |
|---|---|---|
| `nombre` | `id` y `name` del campo; también la clave del error de validación | sí |
| `etiqueta` | Texto visible arriba del campo | sí |
| `type` | `text`, `email`, `tel`, `number`, `date`… | no (`text`) |
| `ayuda` | Una línea de ayuda debajo de la etiqueta | no |
| `valido` | `true` deja el borde verde con una tilde: un dato ya comprobado | no |

Estados:

- **Normal:** borde gris; al hacer foco, borde azul con un halo celeste.
- **Válido:** con `:valido="true"`, borde verde y una tilde a la derecha.
- **Error:** si la validación devuelve un error para `nombre`, el campo se pone rosado con borde rojo y el mensaje
  aparece debajo, en rojo.
  El mensaje sale del `validate()` del componente Livewire; no hay que escribir `@error` en la vista.

```blade
<form wire:submit="consultar" class="space-y-6">
    <x-campo nombre="correo" etiqueta="Correo electrónico" type="email" wire:model="correo" />
    <x-campo nombre="numeroReserva" etiqueta="Número de reserva" wire:model="numeroReserva"
             placeholder="000124-7" ayuda="Está en el correo de confirmación." />
    <x-boton type="submit" class="w-full">Consultar</x-boton>
</form>
```

`nombre` tiene que coincidir con la propiedad que se valida (`correo` ↔ `'correo' => 'required|email'`).

### Aviso — `<x-aviso>`

| Atributo | Valores | Por defecto |
|---|---|---|
| `tipo` | `info`, `error` | `info` |
| `titulo` | Una frase corta en negrita | — |

El texto va en el contenido. El informativo cuenta algo que el cliente tiene que saber (un plazo, un correo enviado);
el de error, algo que salió mal y cómo seguir. Los lectores de pantalla leen el de error en cuanto aparece.

```blade
<x-aviso titulo="Tu lugar está reservado">Tenés 5 minutos para completar el pago.</x-aviso>

<x-aviso tipo="error" titulo="No pudimos registrar el pago">Probá de nuevo en unos minutos.</x-aviso>
```

### Tarjeta — `<x-tarjeta>`

| Atributo | Para qué |
|---|---|
| `titulo` | Título de la tarjeta (opcional) |

Fondo blanco, bordes redondeados y sombra suave. Agrupa un bloque de contenido. No se ponen tarjetas dentro de tarjetas: para separar filas adentro, `divide-divisor`.

```blade
<x-tarjeta titulo="Datos de la reserva">
    <dl class="divide-y divide-divisor">
        <div class="flex justify-between gap-4 py-3">
            <dt class="text-texto-secundario">Salida</dt>
            <dd class="tabular-nums">{{ $reserva->excursion->getFechaSalida()->format('d/m/Y') }}</dd>
        </div>
    </dl>
</x-tarjeta>
```

### Chip de estado — `<x-chip-estado>`

| Atributo | Valores |
|---|---|
| `estado` | El enum `EstadoReserva` o su texto, o el estado del permiso de un excursionista (`estado_permiso`) |

El estado siempre va escrito: el color ayuda, pero no es lo único que lo indica.

| Estado | Cómo se ve |
|---|---|
| Pendiente (reserva o permiso) | Fondo ámbar claro, texto ámbar oscuro |
| Confirmada · permiso Obtenido | Fondo celeste, texto azul |
| Sin Permiso · permiso No Obtenido | Fondo rosado, texto rojo |
| Cancelada | Fondo gris, texto gris oscuro |
| Finalizada | Sin fondo, contorno y texto negros |

```blade
<x-chip-estado :estado="$reserva->estado" />
<x-chip-estado :estado="$excursionista->estado_permiso" />
```

## Accesibilidad

- Los botones y campos miden al menos 48 px de alto, para que sean fáciles de tocar.
- Todo campo lleva su etiqueta visible; los errores quedan asociados al campo (`aria-describedby`).
- El foco del teclado se ve siempre: un contorno azul.
- El layout tiene un enlace «Saltar al contenido» que aparece al navegar con Tab.
