# Datos de prueba de volumen

`DatosDeVolumenSeeder` carga la base como la de una empresa que opera el Camino Inca hace años: para la presentación y
para ver cómo se comportan los listados y los filtros con miles de filas. `migrate:fresh --seed` lo corre al final, después
de los demás *seeders*, que quedan como estaban. **No corre en las pruebas** (`php artisan test`), así que no cambia sus
datos ni las hace más lentas.

## Qué genera

Con las cantidades de fábrica salen, aproximadamente:

| Qué | Cantidad |
|---|---|
| Administradores nuevos | 4: `operaciones@`, `ventas@`, `permisos@` y `gerencia@caminodelinca.test`, contraseña `admin1234` |
| Guías nuevos | 45, `nombre.apellido@caminodelinca.test`, contraseña `guia1234` |
| Servicios nuevos | 10: hoteles de tres categorías en Cusco y en Aguas Calientes, bus privado y tren panorámico |
| Paquetes nuevos | 20 sobre los 3 recorridos: 16 activos y 4 dados de baja |
| Excursiones | unas 1.000, siempre en lunes, desde 14 meses atrás hasta 10 meses adelante (ninguna en febrero) |
| Reservas | unas 3.700, en todos los estados; alrededor del 10 % canceladas, por las cuatro causas |
| Excursionistas | unos 9.000 |
| Pagos y comprobantes | unos 5.200 de cada uno |
| Devoluciones | unas 300 (reintegros y reembolsos) |
| Valoraciones | unas 1.300, con unos 8.500 puntajes por categoría |

Respeta las reglas de los modelos y de `config/reserva.php`: cupo, anticipación mínima, fechas límite, montos, números
de reserva y de comprobante, y un guía por semana (R-01). Ninguna reserva queda con un plazo vencido: el Temporizador no
cancela nada ni manda correos. Los correos de los clientes son de `example.com`, `example.org` y `example.net`.

Las fechas son relativas al día en que se corre; lo demás sale igual para todos por la semilla fija. Al terminar
muestra las cantidades por tabla, las reservas por estado y una reserva de muestra de cada caso (número y correo para
Consultar Reserva), más una excursión en curso con el correo de su guía. Un lunes no hay ninguna en curso, y se muestra
una que sale ese día: el guía la inicia y después reporta las etapas.

## Correrlo solo

Sobre una base que ya tiene los *seeders* de siempre:

```bash
php artisan db:seed --class=DatosDeVolumenSeeder
```

Si ya se cargó (existe alguno de sus usuarios), avisa y no agrega nada. Para empezar de cero: `php artisan migrate:fresh --seed`.

## Achicar las cantidades

Están en constantes al principio de `database/seeders/DatosDeVolumenSeeder.php`:

| Constante | De fábrica | Qué cambia |
|---|---|---|
| `GUIAS` | 45 | Guías nuevos |
| `PAQUETES` | 20 | Paquetes nuevos, contando los dados de baja |
| `PAQUETES_INACTIVOS` | 4 | Cuántos de esos están dados de baja |
| `MESES_HACIA_ATRAS` | 14 | Desde cuándo hay salidas |
| `MESES_HACIA_ADELANTE` | 10 | Hasta cuándo hay salidas |

Las reservas no tienen una constante propia: salen de la cantidad de salidas y de su ocupación. Los tres paquetes de
`PaqueteSeeder` reciben salidas nuevas igual. Con pocos guías, una semana en la que no queda ninguno libre se queda sin
algunas salidas (R-01). Por ejemplo, con `GUIAS = 6`, `PAQUETES = 8` y `MESES_HACIA_ATRAS = 4` la base queda en unas 300
excursiones y 1.150 reservas. Para ver el cambio, volver a correr `php artisan migrate:fresh --seed`.
