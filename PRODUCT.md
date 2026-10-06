# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- **Cliente** (superficie: portal público). Turista hispanohablante de cualquier país que quiere hacer el Camino del
  Inca. No tiene cuenta ni contraseña: se identifica en cada visita con su correo electrónico y su número de reserva
  (formato `000124-7`). Tiene poca experiencia técnica. Reserva y paga mayormente desde la computadora; el celular es
  secundario pero tiene que funcionar completo (por ejemplo, al abrir el enlace de un correo).
- **Administrador** y **Guía** (superficie: panel en Filament). Fuera del alcance del portal; se mencionan sólo porque
  sus acciones (validar permisos, reportar etapas) cambian lo que el cliente ve en su reserva.

## Product Purpose

Sistema web de reservas de excursiones al Camino del Inca. El cliente consulta los paquetes, reserva una salida para
uno o más excursionistas, paga la seña y el saldo, sigue el estado de su reserva y, al terminar, valora los servicios.
El éxito es que una persona sin experiencia técnica pueda reservar, pagar y gestionar su viaje sola, sin crear una
cuenta y sin dudas sobre en qué estado está su reserva o cuánto debe.

Proyecto académico: cátedra Desarrollo de Software (UNPSJB, 2026), Grupo 11. Evaluación final el 13/11/2026.

## Operating Context

- Flujo del cliente en el portal: Listar Paquetes (CU-05) → Consultar Paquete (CU-06) → Realizar Reserva (CU-14) →
  Pagar Reserva (CU-15, Mercado Pago Checkout Pro) → Consultar Reserva (CU-18) → Pagar Saldo Pendiente (CU-16) →
  Valorar Servicios (CU-23). Además: Modificar Reserva (CU-20), Cancelar Reserva (CU-21), Solicitar Reintegro (CU-17)
  y Solicitar Reprogramación por Falta de Permisos (CU-19).
- Al reservar, el cupo queda retenido 5 minutos mientras el cliente paga; si no paga, se libera.
- El sistema notifica al cliente por correo (CU-13) en ocho casos de uso: confirmación, pagos, permisos, cancelaciones
  automáticas, etc. El correo trae el número de reserva que después se usa para entrar al portal.
- La reserva puede cancelarse sola por falta de pago del saldo (CU-22) o por falta de confirmación de permisos (CU-26).
- Montos en USD.

## Capabilities and Constraints

- Stack: Laravel 12, Blade y Livewire 4 (portal), Filament 5 (panel), Tailwind CSS 4, PostgreSQL.
- Estados de la reserva: Pendiente, Confirmada, Sin Permiso, Cancelada, Finalizada. Estado del saldo: Adeudado,
  Abonado. Tipos de pago: Seña, Saldo, Total. Motivos de devolución: Reintegro, Reembolso. Estado del permiso de cada
  excursionista: Pendiente, Obtenido, No Obtenido.
- MVC sin capa de servicios; el código tiene que ser simple y explicable por el grupo en la evaluación.
- Sin instalar paquetes nuevos de Composer o npm sin avisar.
- Los textos del portal salen de la ERS y de los casos de uso (en el Drive del grupo). Si falta un dato del diseño, se
  pregunta antes de inventarlo.

## Brand Commitments

- Nombre: **Camino del Inca**.
- Idioma: español rioplatense con voseo («Ingresá tu correo», «Consultá tu reserva»).
- **Diseño vigente del portal: interfaz clara, con la tienda de Apple (apple.com/store) como referencia** (decidido
  por Tomás el 06/10/2026). Fondo gris muy claro, tarjetas blancas con sombra suave, botones azules en forma de
  pastilla y títulos grandes en dos tonos. Los valores salen de los tokens del bloque `@theme` de
  `resources/css/app.css`; el uso de cada uno está en `docs/guia-visual-portal.md`.

  | Token | Valor | Uso |
  |---|---|---|
  | `fondo` | `#F5F5F7` | Fondo de la página |
  | `tarjeta` | `#FFFFFF` | Tarjetas, campos y encabezado |
  | `divisor` | `#D2D2D7` | Líneas entre filas y secciones |
  | `borde-campo` | `#86868B` | Borde de los campos |
  | `accion` | `#0071E3` | Botón primario, con texto blanco; foco |
  | `accion-hover` | `#0077ED` | Botón al pasar el mouse |
  | `accion-presionado` | `#006EDB` | Botón presionado |
  | `enlace` | `#0066CC` | Enlaces y botón secundario |
  | `aviso` | `#E8F2FD` | Avisos informativos; estados Confirmada y Obtenido |
  | `texto` | `#1D1D1F` | Texto principal |
  | `texto-secundario` | `#6E6E73` | Descripciones y etiquetas |
  | `exito` | `#00802F` | Campo válido |
  | `error` / `error-fondo` | `#D70015` / `#FFF2F4` | Errores; estados Sin Permiso y No Obtenido |
  | `ambar` / `ambar-fondo` | `#9A5B00` / `#FDF3DC` | Estado Pendiente y saldo adeudado |
  | `gris-fondo` | `#E8E8ED` | Estado Cancelada y botón deshabilitado |

- **Tipografía: Inter**, una sola familia para títulos y textos, desde Google Fonts.

## Paleta reservada para un posible modo oscuro

La paleta del prototipo aprobado original queda guardada para un posible modo oscuro. **No se implementa por ahora**:
no hay modo oscuro en el portal ni variantes `dark:` en las vistas.

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

## Evidence on Hand

- Textos de la ERS: descripciones de paquetes, recorridos, etapas y servicios, y las políticas de cancelación y
  reintegro (en el Drive del grupo, no en el repositorio).
- **Fotos y logotipo: todavía no existen; se buscarán más adelante.** No inventarlos ni usar imágenes de stock como si
  fueran propias; dejar el lugar preparado.
- Sin testimonios, valoraciones reales ni cifras de clientes: no fabricarlos.

## Product Principles

1. **Sin cuenta, sin fricción.** Correo y número de reserva alcanzan para todo; nunca pedir una contraseña.
2. **El estado siempre a la vista.** En cada pantalla de la reserva el cliente sabe en qué estado está, cuánto pagó,
   cuánto debe y qué puede hacer a continuación.
3. **Lenguaje de viajero, no de sistema.** Textos claros en voseo, sin jerga técnica; los errores dicen qué pasó y cómo
   seguir.
4. **Las acciones con dinero o irreversibles se confirman.** Pagar, cancelar y pedir reintegro muestran las
   consecuencias (montos, plazos) antes de confirmar.
5. **Simple de explicar.** Cada pantalla tiene que poder defenderse línea por línea en la evaluación.

## Accessibility & Inclusion

- Público con poca experiencia técnica: objetivos táctiles y textos generosos, etiquetas visibles en todos los campos,
  errores junto al campo.
- WCAG 2.1 AA como piso (contraste del texto sobre el fondo claro, foco visible, navegación por teclado).
