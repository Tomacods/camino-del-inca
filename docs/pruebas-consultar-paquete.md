# Pruebas de CU-06 Consultar Paquete

Las reglas y la corrección del DS-06 están en [cambios de diseño](cambios-diseno-cu06.md).
El alcance de estas pruebas es el portal del cliente del PR #53.

## Pruebas automáticas

`tests/Feature/ConsultarPaqueteTest.php` cubre:

| Caso | Qué comprueba |
|---|---|
| Curso normal | Nombre, recorrido, etapas, servicios, porteadores y tres precios |
| Salidas disponibles | Excluye salidas pasadas, a menos de tres meses o sin cupo; muestra el cupo descontando retenciones y el guía |
| A1, paquete inactivo | Redirige al listado y conserva el aviso, sin mostrar el paquete dado de baja |
| Identificador inexistente | Redirige al listado con el mismo aviso |
| A2 | El botón lleva al formulario de CU-14 con el paquete y la fecha correctos |
| Promedio | Promedia sólo las valoraciones del paquete; avisa cuando no hay valoraciones |
| Precios fraccionarios | Conserva y muestra los dos decimales |
| Día exacto del límite | El 25/10/2026 a las 23:59 muestra la salida del lunes 25/01/2027 y abre CU-14 |
| Cambio de día | El 26/10/2026 a las 00:01 excluye esa salida del detalle, del listado y de la búsqueda por fecha; CU-14 también la rechaza por URL directa |
| Fin de mes | El 30/11/2026 más tres meses llega al 28/02/2027; la salida del lunes 01/03 se muestra y CU-14 la admite |

Las dos pruebas del límite usan lunes reales y recorren el detalle y el formulario de reserva. No reemplazan los
modelos por simulaciones. Las pruebas existentes de CU-05 cubren además la búsqueda por fecha y sus errores de formato.

## Comprobación manual en navegador

Pendiente de ejecutar después de integrar los cambios. Preparar un paquete activo con sus servicios y excursiones,
un paquete inactivo, una salida con cupo, otra sin cupo y una salida futura a menos de tres meses.

| Paso | Resultado esperado |
|---|---|
| Abrir `/paquetes` sin iniciar sesión y pulsar «Ver el paquete» | Muestra el detalle completo del paquete activo |
| Revisar importes fraccionarios, por ejemplo 750,49 / 60,75 / 45,25 | Los tres importes conservan dos decimales |
| Revisar las salidas | Sólo aparecen las que cumplen tres meses y tienen cupo; cada una muestra fecha, lugares y guía |
| Pulsar «Reservar» | Abre el formulario de CU-14 para esa salida |
| Abrir por URL un paquete inactivo y un identificador inexistente | Vuelve al listado con el aviso de paquete no disponible |
| Abrir un paquete activo sin salidas reservables | Conserva el detalle, muestra el aviso y no ofrece reservar una salida |
| Abrir un paquete sin valoraciones y otro con valoraciones | Muestra el aviso o el promedio correspondiente |
| Revisar escritorio y pantalla angosta | Precios, tarjetas, fechas y botones se leen y se pueden usar |

## Alcance administrativo

La consulta administrativa de paquetes activos e inactivos y sus permisos por rol sigue pendiente en el recurso de
Paquetes de Filament. Estas pruebas del portal no permiten darla por validada.

## Resultado de la revisión del 23/10/2026

| Comprobación | Resultado |
|---|---|
| `php artisan migrate:fresh --seed` | Correcto en SQLite en memoria |
| Suite completa | 611 pruebas y 2693 aserciones, sin fallos |
| `php vendor/bin/pint` | Correcto |
| PostgreSQL | Pendiente: no se pudo establecer la conexión en este entorno |
| Navegador | Pendiente de la comprobación manual indicada arriba |

El lanzador estándar de `php artisan test` no pudo correr limpiamente en la copia aislada por las restricciones de
permisos y la ausencia del archivo de entorno. La suite completa se ejecutó con PHPUnit directamente, manteniendo
las mismas suites, variables y opciones de `phpunit.xml`. Para evitar el chequeo de lectura bloqueado por el
entorno, se adelantó la carga de `vendor/autoload.php` con `auto_prepend_file` y se omitió solamente el atributo
`bootstrap` en una copia temporal de la configuración. Esa copia y los archivos temporales no se suben al PR.
