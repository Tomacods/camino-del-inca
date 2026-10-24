# Modificar y dar de baja paquetes: correcciones de CU-02 y CU-03

Complemento de R-02, R-03 y las descripciones breves del Documento 3, a partir de la revisión del PR #58.
Los casos no tienen un diagrama de secuencia propio. No se cambia el esquema de la base ni se agregan dependencias.

## Reservas y pagos en curso

Se conserva la prohibición de modificar o dar de baja un paquete con reservas Pendiente, Confirmada o Sin Permiso.

El 24/10/2026 se acordó además **esperar a que se liberen las plazas retenidas**. Mientras alguna excursión del
paquete tenga `plazas_retenidas > 0`, se rechazan la modificación y la baja con un aviso. Así no se cambia el precio,
el cupo, el guía ni los servicios durante un pago y no se registra una reserva nueva para un paquete Inactivo.
Si el pago se completa, la reserva activa mantiene la restricción; si la retención se cancela o la tarea la libera
al vencer, vuelve a permitirse la operación cuando no quedan otras reservas activas ni retenciones.

Es una aclaración acordada para incorporar a R-02 y R-03 de la ERS, que no detallaban el tratamiento de las retenciones.
No se cancela un pago en curso ni se libera su cupo desde el panel.

CU-14 vuelve a consultar el estado del paquete al retener cupo, con la excursión bloqueada. Un formulario abierto
antes de una baja no puede iniciar un pago después de ella. Si CU-14 retiene primero, la baja espera el bloqueo y
rechaza la operación al encontrar las plazas retenidas; si la baja termina primero, la retención se rechaza.

## Edición de excursiones

Antes de validar el formulario, el modelo bloquea el paquete y consulta sus excursiones actuales:

- Reemplaza la fecha recibida de cada excursión existente por su fecha guardada. El campo deshabilitado no es una
  fuente confiable: tanto el control de superposición como la escritura deben usar la misma fecha real.
- Si el formulario omite una excursión existente, lo rechaza y pide recargarlo. Esto incluye una salida que otro
  administrador agregó después de abrir el formulario: Filament no llega a sincronizar esa ausencia como una baja.
- Si una clave de excursión existente no pertenece al paquete, también rechaza el formulario.

Las salidas nuevas conservan la validación de lunes, fecha futura, cupo, guía y superposición. Las existentes
conservan su fecha y permiten cambiar cupo y guía, sujeto a las reglas de R-02.

El bloqueo del paquete se mantiene hasta terminar la transacción del formulario. Dos ediciones o una edición y una
reactivación del mismo paquete se turnan. Los guías se bloquean en orden antes de actualizar las excursiones, como
en CU-01; luego se bloquean las excursiones en orden de `id_excursion` y se revisan reservas y retenciones.
Servicios, excursiones y datos del paquete se confirman juntos o se deshacen juntos.

## Reactivación

Se revisan las excursiones futuras y las que ya comenzaron pero cuyo último día todavía no pasó. El último día
se incluye: `fecha_salida + duracion_dias - 1`. Por ejemplo, una salida del lunes con duración de cuatro días se
revisa también el miércoles y durante todo el jueves. Los recorridos que ya terminaron no bloquean la reactivación.

Se conserva el criterio del PR: las excursiones de paquetes Inactivos no ocupan al guía para nuevas asignaciones;
al reactivar se vuelve a verificar su disponibilidad. Esta excepción por estado debe quedar expresa en R-01,
que actualmente habla de excursiones del mismo paquete o de cualquier otro sin explicitarla.

## Firmas para el diagrama de clases

| Clase | Método | Retorno |
|---|---|---|
| Paquete | tieneReservasActivas() | bool |
| Paquete | tienePlazasRetenidas() | bool |
| Paquete | sePuedeModificar() | bool |
| Paquete | prepararExcursionesParaModificar(array $salidas), dentro de la transacción de edición | array |
| Paquete | exigirSinReservasActivas(), con bloqueo durante la transacción | void |
| Paquete | modificarPaquete(array $datos) | void |
| Paquete | darDeBaja() | void |
| Paquete | reactivar() | void |
| Paquete | confirmarGuiasDisponibles(array $salidas, int $idRecorrido, ?int $idPaquete = null), estático | void |

`exigirSinReservasActivas()` también controla las plazas retenidas según la aclaración acordada.
La guía de pruebas está en [pruebas de CU-02 y CU-03](pruebas-modificar-eliminar-paquete.md).
