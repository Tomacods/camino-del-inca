# Pruebas de CU-01 Crear Paquete

Las reglas y las firmas corregidas están en [la revisión de diseño](cambios-diseno-cu01.md).
Se verifican junto con CU-06 para conservar la consulta administrativa y el portal.

## Cobertura automática

tests/Feature/CrearPaqueteTest.php cubre:

| Caso | Resultado esperado |
|---|---|
| Alta válida | Un paquete Activo, fecha de hoy, servicios y excursiones con sus cupos y guías |
| Datos obligatorios | Errores de formulario, sin crear el paquete |
| Nombre repetido | Error de unicidad |
| Día distinto de lunes o fecha pasada | Error en la fecha de la excursión |
| Dos salidas el mismo día | Error de fecha repetida |
| Cupo cero o valores que exceden el esquema | Error numérico antes de guardar |
| Falla de la segunda excursión | Rollback del paquete, servicios y todas las excursiones |
| Guía ocupado | Se rechaza la superposición; el mismo guía puede salir una semana después si no se superpone |
| Conflicto entre la primera consulta y el guardado | La comprobación final lo detecta y el componente mantiene record = null |
| Corregir el guía y reintentar después del conflicto | Respuesta 200 y alta completa, sin 404 ni relaciones perdidas |
| Salida a menos de tres meses | Se crea, se consulta en el panel, no aparece en el portal y CU-14 la rechaza |
| Permisos | Guía recibe 403 en listado, alta y componente; visitante redirige al inicio de sesión |

La escritura intercalada de las pruebas de formulario se simula con un listener de consultas en la **misma conexión**.
Sirve para verificar la segunda comprobación y el reintento, pero no demuestra el bloqueo entre dos pedidos reales.

ConsultarPaquetePanelTest, ConsultarPaqueteTest y ListarPaquetesTest conservan la cobertura de CU-05/CU-06:
consulta de activos e inactivos, acción Ver, cupo derivado, tres meses calendario y acceso por rol.

## Comprobación manual

| Paso | Resultado esperado |
|---|---|
| Administrador: Paquetes → Crear paquete | Formulario visible; recorrido, servicios y guías precargados |
| Crear un paquete con dos lunes válidos | Vuelve al listado; Ver abre el detalle con ambas salidas |
| Probar martes, fecha pasada, cupo cero y fecha repetida | Error sin escrituras parciales |
| Elegir un guía que conduce otra salida superpuesta | Error; corregir el guía y volver a enviar permite crear |
| Crear una salida futura a menos de tres meses | Visible en el panel y oculta en listado, detalle y búsqueda públicos |
| Guía: abrir listado, alta y detalle por URL directa | 403 |
| Visitante: abrir listado o alta del panel | Redirección al inicio de sesión |

## Comprobación de concurrencia en PostgreSQL

Usar una base exclusiva de pruebas. En dos conexiones independientes:

1. Preparar el mismo guía y recorrido; las dos altas deben haber visto inicialmente al guía libre.
2. La primera conexión abre una transacción, ejecuta confirmarGuiasDisponibles(), crea su paquete y excursión y
   mantiene la transacción abierta.
3. La segunda abre otra transacción y ejecuta confirmarGuiasDisponibles() para un período superpuesto.
   Debe esperar el bloqueo del guía, antes de insertar su paquete o excursión.
4. Confirmar la primera conexión. La segunda debe continuar, detectar el conflicto y rechazar su alta.
   Debe quedar una sola excursión para ese guía y período, sin deadlock.
5. Repetir con períodos no superpuestos: ambos paquetes deben poder guardarse.

La comprobación del bloqueo no se reemplaza por SQLite ni por dos escrituras dentro de una misma transacción.

## Resultado de la revisión del 23/10/2026

| Comprobación | Resultado |
|---|---|
| Migraciones con seed | Correctas en una base SQLite exclusiva de revisión |
| Suite completa | 634 pruebas y 2839 aserciones, sin fallos |
| Alta y consulta focalizadas | 34 pruebas y 191 aserciones, sin fallos |
| Pint | Correcto en los archivos PHP del cambio |
| Dos conexiones reales a PostgreSQL | Pendiente: el servidor local no estuvo accesible |
| Navegador | Pendiente de la comprobación manual indicada arriba |

La suite se ejecutó directamente con PHPUnit y las suites de phpunit.xml en una copia aislada que combina CU-01
con main después del PR #53. Fue necesario adaptar temporalmente el bootstrap y las comprobaciones de lectura del
entorno. Los lanzadores, la configuración temporal y la base de revisión no forman parte del commit.

