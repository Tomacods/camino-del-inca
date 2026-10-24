# Pruebas de CU-02 y CU-03

Las reglas y firmas están en [las correcciones de diseño](cambios-diseno-cu02-cu03.md).

## Pruebas automáticas

`ModificarPaqueteTest` cubre la edición normal, fechas y recorrido inmutables, nuevas salidas los lunes,
guías sin superposiciones y la restricción de reservas activas. Las regresiones agregadas verifican:

- Una fecha manipulada no permite asignar un guía ocupado ni provoca un conflicto falso en otra fecha.
- Un formulario desactualizado no borra una salida agregada desde otra pestaña ni cambia parcialmente servicios
  o datos del paquete. Después de recargar, permite guardar conservando las excursiones.
- Omitir una excursión del estado enviado no permite eliminarla.
- Las retenciones impiden abrir la edición o guardar cambios de precio, servicios y cupo. Tras liberarlas,
  se permite guardar si no hay reservas activas.

`EliminarPaqueteTest` cubre la baja lógica y reactivación normales, reservas activas, permisos y visibilidad.
Las regresiones verifican:

- La baja se rechaza con plazas retenidas, y el pago puede registrar la reserva con el paquete todavía Activo.
- Tras liberar la retención, puede darse de baja. Una excursión con una relación al paquete cargada antes de la baja
  tampoco puede retener cupo después: el estado se consulta de nuevo en la base.
- Al reactivar se detectan superposiciones en recorridos ya comenzados, incluido todo su último día.
  Un guía libre permite reactivar; los recorridos que terminaron el día anterior no bloquean la operación.

`RealizarReservaTest` verifica también el formulario del cliente abierto antes de la baja: confirmar después
no retiene plazas, no guarda una reserva en curso y no inicia el pago.

Las pruebas de dos formularios son pedidos Livewire secuenciales sobre la misma base de pruebas. Reproducen la
pérdida de excursiones sin necesitar simultaneidad real y no sustituyen una prueba de bloqueos en PostgreSQL.

## Comprobación manual pendiente

1. Como administrador, abrir el mismo paquete en dos pestañas. Agregar una salida desde la segunda y guardar.
   Cambiar el nombre desde la primera: debe avisar que hay que recargar, sin borrar ni modificar parcialmente nada.
2. Recargar el formulario anterior, modificar y guardar. Deben conservarse todas las excursiones.
3. Como cliente, confirmar una reserva hasta retener cupo, sin terminar el pago. En el panel, intentar modificar
   y dar de baja ese paquete: ambas operaciones deben rechazarse y explicar que hay plazas retenidas.
4. Completar ese pago: la reserva debe quedar Pendiente y el paquete Activo. Repetir con una retención cancelada
   o liberada por vencimiento: sin otras reservas activas, debe permitirse modificar y dar de baja.
5. Abrir el formulario del cliente sin confirmar; dar de baja el paquete desde el panel y después confirmar:
   no debe crearse la reserva en curso ni llegarse al pago.
6. Verificar una reactivación con un recorrido que comenzó y sigue en curso, y probar el último día del recorrido.

## Bloqueos en PostgreSQL

Comprobar en dos conexiones que una edición mantiene bloqueado el paquete hasta confirmar o deshacer el guardado,
y que la retención y la baja se turnan sobre la excursión. Usar datos de prueba y deshacer las transacciones;
no reconstruir la base de desarrollo para esta comprobación.

## Resultado del 24/10/2026

| Comprobación | Resultado |
|---|---|
| Suite completa con `php artisan test --compact` | 688 pruebas, 3118 aserciones; sin fallos ni advertencias |
| Paquetes, retenciones y registro del pago en PostgreSQL | 97 pruebas, 532 aserciones; sin fallos, advertencias ni pruebas riesgosas |
| `migrate:fresh --seed` | Correcto, con conexión forzada a SQLite en memoria |
| Pint en todo el proyecto | Correcto |
| `git diff --check` | Correcto |
| Navegador | Pendiente de la comprobación manual anterior |

El servicio PostgreSQL de desarrollo estaba detenido y no pudo iniciarse con los permisos disponibles.
Se usaron los binarios instalados de PostgreSQL 18.6 para crear una instancia descartable, restringida a
`127.0.0.1:55458`, con una base exclusiva `revision_pr58`. El bootstrap de pruebas verificó la base, el usuario y
el puerto antes de permitir las pruebas. No se ejecutaron migraciones ni escrituras sobre la base de desarrollo.

Además de las pruebas focalizadas, se comprobaron cuatro cruces reales entre **dos procesos PHP y conexiones
independientes**. En cada caso se verificó en `pg_stat_activity` que el segundo proceso esperaba un bloqueo:

1. Baja primero: la retención esperó y, al continuar, encontró el paquete Inactivo y se rechazó.
2. Retención primero: la baja esperó y, al continuar, detectó las plazas retenidas y se rechazó.
3. Dos ediciones: la segunda esperó el paquete y rechazó el formulario que omitía la nueva excursión confirmada
   por la primera. Se conservaron ambas salidas.
4. Dos reactivaciones con el mismo guía y período: la segunda esperó el guía y detectó la superposición
   después de confirmarse la primera. El segundo paquete siguió Inactivo.

Los cruces confirmaron transacciones únicamente en la base descartable para hacer visibles los cambios entre
conexiones. La instancia temporal se detuvo al terminar; el servicio de desarrollo se mantuvo detenido.
Los lanzadores y las credenciales ficticias del entorno temporal no forman parte del repositorio.
