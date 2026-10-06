# Reparto de casos de uso

Reparto en cuatro áreas, una por integrante (decidido por el grupo el 05/10). Las áreas agrupan casos de uso que tocan
las mismas pantallas y las mismas clases, para que cada uno pueda avanzar sin pisar a los demás. **Falta definir** los
responsables de las tareas que cruzan todas las áreas, salvo la de base de datos.

Dónde se programa cada caso de uso:

- **Portal** — pantallas del cliente, con Blade y Livewire.
- **Panel** — pantallas del administrador y del guía, con Filament.
- **Tarea programada** — la ejecuta el sistema solo (actor Temporizador).

## Base compartida (TP5, antes de repartir)

Se hace primero y entre todos, porque los 26 casos de uso dependen de ella. Termina con la etiqueta `v0.1`.

| Parte | Tablas (ver [esquema](esquema-base-de-datos.md)) | Responsable |
|---|---|---|
| Usuarios y acceso | USUARIO, GUIA; inicio de sesión por rol | Tomás |
| Catálogo | RECORRIDO, ETAPA, SERVICIO, PAQUETE, PAQUETE_SERVICIO | Tomás |
| Excursión y reserva | EXCURSION, RESERVA, EXCURSIONISTA | Tomás |
| Pagos y valoraciones | PAGO, COMPROBANTE, DEVOLUCION, VALORACION, DETALLE_VALORACION | Tomás |

Cada parte incluye la migración, el modelo con sus relaciones y los datos de prueba (*seeders*). Los recorridos, las
etapas, los servicios y las cuentas de administrador y guía son datos precargados (ERS, sección 2.5): van en los
*seeders*.

Las partes se integran en el orden de la tabla, porque cada una referencia a las anteriores: «Usuarios y acceso» y
«Catálogo» pueden avanzar a la vez; «Excursión y reserva» necesita PAQUETE, GUIA y ETAPA; «Pagos y valoraciones»
necesita RESERVA.

## Área A — Paquetes, valoraciones y acceso

**Responsable:** Mariano

| CU | Nombre | Actor | Dónde |
|---|---|---|---|
| CU-01 | Crear Paquete | Administrador | Panel |
| CU-02 | Modificar Paquete | Administrador | Panel |
| CU-03 | Eliminar Paquete | Administrador | Panel |
| CU-05 | Listar Paquetes | Cliente | Portal |
| CU-06 | Consultar Paquete | Cliente | Portal |
| CU-08 | Listar Valoraciones | Administrador | Panel |
| CU-09 | Consultar Valoración | Administrador | Panel |
| CU-23 | Valorar Servicios | Cliente | Portal |
| CU-24 | Iniciar Sesión | Administrador, Guía | Panel |
| CU-25 | Cerrar Sesión | Administrador, Guía | Panel |

## Área B — Reserva y pagos

**Responsable:** Tomás

| CU | Nombre | Actor | Dónde |
|---|---|---|---|
| CU-14 | Realizar Reserva | Cliente | Portal |
| CU-15 | Pagar Reserva | Cliente, Pasarela de Pago | Portal |
| CU-16 | Pagar Saldo Pendiente | Cliente, Pasarela de Pago | Portal |

Son pocos pero son los más difíciles: retención del cupo por 5 minutos, concurrencia (riesgo N.º 13) e integración con
Mercado Pago. El paquete `mercadopago/dx-php` se agrega en esta área.

## Área C — Gestión de la reserva

**Responsable:** Mora

| CU | Nombre | Actor | Dónde |
|---|---|---|---|
| CU-07 | Listar Reservas | Administrador | Panel |
| CU-17 | Solicitar Reintegro | Cliente | Portal |
| CU-18 | Consultar Reserva | Cliente, Administrador | Portal y Panel |
| CU-19 | Solicitar Reprogramación por Falta de Permisos | Cliente | Portal |
| CU-20 | Modificar Reserva | Cliente | Portal |
| CU-21 | Cancelar Reserva | Cliente | Portal |

## Área D — Permisos, excursión, notificaciones y cancelaciones automáticas

**Responsable:** César

| CU | Nombre | Actor | Dónde |
|---|---|---|---|
| CU-04 | Validar Permisos de Reserva | Administrador | Panel |
| CU-10 | Listar Integrantes de la Excursión | Guía | Panel |
| CU-11 | Iniciar Recorrido | Guía | Panel |
| CU-12 | Reportar Etapa | Guía | Panel |
| CU-13 | Notificar Cliente | Sistema (incluido), Servicio de Correo | Correo |
| CU-22 | Cancelar Reserva por Falta de Pago de Saldo | Temporizador | Tarea programada |
| CU-26 | Cancelar Reserva por Falta de Confirmación de Permisos | Temporizador | Tarea programada |

CU-13 lo incluyen ocho casos de uso (CU-04, 12, 15, 16, 17, 21, 22 y 26): conviene tenerlo temprano, aunque al
principio sólo envíe la confirmación de la reserva.

## Tareas que cruzan todas las áreas

| Tarea | Qué hace | Responsable |
|---|---|---|
| Integración | Cuida que `main` funcione; arma la etiqueta estable de los viernes | |
| Base de datos | Integra los cambios de esquema; mantiene el Documento de Normalización al día | Tomás |
| Documento de prueba (TP7) | Junta los casos de prueba de cada área | |
| Manual de usuario (TP7) | No técnico: capturas y pasos, para cualquier lector | |

Todos programan su área; estas tareas se suman, no reemplazan.

## Orden propuesto

Las fechas de entrega son las de la planificación de la cátedra; las etapas intermedias son una propuesta.

| Viernes | Etiqueta | Qué tiene que funcionar |
|---|---|---|
| 16/10 | `v0.1` | Base compartida: las 15 tablas, modelos, datos de prueba e inicio de sesión por rol |
| 23/10 | `v0.2` | Nudo del problema: CU-05, CU-06, CU-14, CU-15 y CU-13 (confirmación de la reserva) |
| 30/10 | `v0.3` | Resto del portal del cliente y del panel |
| 06/11 | `v0.4` | Los 26 casos de uso; desde acá sólo arreglos, pruebas y manual |
| 13/11 | `v1.0` | Evaluación final y exposición |

El lunes 09/11 se entrega la documentación del Punto de Control N.º 3.

## Pendientes de diseño que frenan al Área B

Para consultar a la cátedra antes de programar CU-14 y CU-15:

1. **Vencimiento de la retención.** Con `plazas_retenidas` como contador en Excursión, falta definir dónde queda la
   hora de inicio de cada retención para liberar las plazas a los 5 minutos si el cliente abandona el pago.
2. **Concurrencia.** Sumar y restar sobre `plazas_retenidas` desde dos clientes a la vez tiene que ser atómico.
