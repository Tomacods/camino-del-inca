# Pruebas de CU-06 Consultar Paquete

Las reglas y la corrección del DS-06 están en [cambios de diseño](cambios-diseno-cu06.md).
El alcance de estas pruebas incluye el portal del cliente y la consulta administrativa del PR #53.

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

## Comprobación en navegador

Ejecutada sobre PostgreSQL en una base exclusiva de revisión, con datos de prueba: paquete activo con servicios,
paquete inactivo, paquete sin salidas ni valoraciones, importes fraccionarios y excursiones pasadas, completas,
a menos de tres meses y reservables. El portal se revisó en escritorio y a 390 píxeles CSS de ancho.

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

## Consulta administrativa y permisos

`tests/Feature/ConsultarPaquetePanelTest.php` agrega seis pruebas:

| Caso | Qué comprueba |
|---|---|
| Listado administrativo | Muestra activos e inactivos y aplica el filtro por estado |
| Detalle administrativo | Recorrido, etapas, servicios, porteadores, tres precios con centavos y promedio |
| Todas las salidas | Incluye pasadas, próximas y completas, ordenadas por fecha; descuenta retenciones e integrantes de las tres clases de reservas que ocupan cupo |
| Ausencia de datos | Informa cuando no hay excursiones ni valoraciones |
| Rol Guía | Recibe 403 al abrir listado o detalle por URL directa, también para un paquete inactivo |
| Visitante | Debe iniciar sesión para entrar al panel; el portal sigue siendo público |

La sesión de administrador tampoco habilita paquetes inactivos en la ruta pública.

## Resultado de la revisión del 23/10/2026

| Comprobación | Resultado |
|---|---|
| Migraciones con seed | Correctas en PostgreSQL, base exclusiva de revisión |
| Suite completa en SQLite | 617 pruebas y 2735 aserciones; 0 fallos (101,4 s) |
| Suite completa en PostgreSQL | 617 pruebas y 2735 aserciones; 0 fallos (374,8 s) |
| CU-06, portal y panel | 17 pruebas y 87 aserciones; 0 fallos |
| Pint | Correcto |
| Navegador | Portal, enlace a CU-14, avisos A1 y sin salidas, escritorio y móvil; panel con activos, inactivos, filtro y todas las excursiones; guía con 403 en listado y detalle |

### Ejecución y entorno

Se ejecutó PHPUnit directamente con las mismas suites y variables de `phpunit.xml`. En la copia aislada, el chequeo
de lectura del lanzador estándar falla: se adelantó `vendor/autoload.php` con `auto_prepend_file` y se omitió solamente
el atributo `bootstrap` en una copia temporal de la configuración. No se cambió la configuración del repositorio.

La corrida de PostgreSQL usó `camino_cu06_review_20261023`; la base de desarrollo no se modificó. Para el navegador se
publicaron en la copia temporal los recursos de Filament y Livewire como archivos estáticos, porque el entorno
bloquea el chequeo de lectura de las respuestas de archivos dinámicos. No fue necesario cambiar código del producto.
Los lanzadores, datos adicionales, recursos publicados y configuraciones temporales no se suben al PR.

La [revisión del Drive](https://docs.google.com/document/d/1ZrazgNyMoCciwDL_V759mS9zRsrALbYws8G-mhT2APs/edit)
registra las reglas y la corrección de DS-06 en **Revisiones de código vs diseño**. Tras aprobar e integrar el PR #53,
CU-06 queda cubierto en el portal y el panel. Al integrar CU-01 (#54), conservar la consulta y sus permisos como indica
[la nota de integración](cambios-diseno-cu06.md#integración-con-cu-01-pr-54).
