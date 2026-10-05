# Esquema de la base de datos

Las 15 tablas del **Documento de Normalización de Base de Datos** (Doc 6), con la corrección de la devolución del
02/10: se eliminó `RETENCION_CUPO` y `EXCURSION` sumó `plazas_retenidas`.

Es la referencia para escribir las migraciones y los modelos en el TP5. **El documento manda:** si al programar hace
falta cambiar una tabla, se cambia también el Doc 6.

**Clave primaria** en negrita · *clave foránea* en cursiva · ***clave primaria que también es foránea*** en ambas.

Los nombres de las tablas figuran en mayúscula, como en el Doc 6; en la base van en minúscula (`usuario`,
`paquete_servicio`).

| Tabla | Columnas |
|---|---|
| USUARIO | **id_usuario**, correo, password, rol |
| GUIA | ***id_usuario***, nombre, apellido |
| RECORRIDO | **id_recorrido**, nombre, duracion_dias, cantidad_campings |
| ETAPA | **id_etapa**, *id_recorrido*, nombre, orden |
| SERVICIO | **id_servicio**, tipo, nombre |
| PAQUETE | **id_paquete**, *id_recorrido*, nombre, precio_base, costo_noche_extra_cusco, costo_equipo_camping, cantidad_porteadores, estado, fecha_creacion |
| PAQUETE_SERVICIO | ***id_paquete***, ***id_servicio*** |
| EXCURSION | **id_excursion**, *id_paquete*, *id_guia*, *id_etapa_actual*, fecha_salida, cupo, plazas_retenidas, fecha_hora_inicio_recorrido |
| RESERVA | **id_reserva**, *id_excursion*, numero_reserva, correo_electronico, fecha_reserva, estado, estado_saldo, noches_extra_antes, noches_extra_despues, fecha_limite_saldo, fecha_limite_confirmacion |
| EXCURSIONISTA | **id_excursionista**, *id_reserva*, nombre, apellido, documento_pasaporte, equipo_camping, estado_permiso |
| PAGO | **id_pago**, *id_reserva*, fecha, monto, tipo_pago, medio_pago |
| COMPROBANTE | **id_comprobante**, *id_pago*, numero_comprobante, fecha_emision |
| DEVOLUCION | **id_devolucion**, *id_reserva*, fecha, monto, motivo |
| VALORACION | **id_valoracion**, *id_reserva*, fecha, puntaje_experiencia_general, comentario |
| DETALLE_VALORACION | ***id_valoracion***, **categoria**, puntaje |

## Orden de las migraciones

Una tabla se crea después de las tablas a las que referencia:

1. USUARIO → GUIA
2. RECORRIDO → ETAPA
3. SERVICIO
4. PAQUETE → PAQUETE_SERVICIO
5. EXCURSION (referencia a PAQUETE, GUIA y ETAPA)
6. RESERVA → EXCURSIONISTA
7. PAGO → COMPROBANTE
8. DEVOLUCION
9. VALORACION → DETALLE_VALORACION

## Lo que no es una columna

Se calcula cada vez que se necesita, con un método del modelo; no se guarda:

- **Cupo disponible** = `cupo` − plazas de las reservas "Pendiente", "Confirmada" o "Sin Permiso" − `plazas_retenidas`
- Monto total de la reserva, saldo y porcentaje de devolución
- Cantidad de integrantes de la reserva
- Promedio de valoraciones del paquete

El monto de una devolución se calcula sobre **la suma de todos los pagos** de la reserva (devolución del 02/10).

## Enumeraciones

Son columnas con dominio cerrado: sólo admiten los valores de la lista, escritos así, con mayúscula y tilde.

| Enumeración | Valores | Columna |
|---|---|---|
| Rol | Administrador, Guía | `usuario.rol` |
| EstadoPaquete | Activo, Inactivo | `paquete.estado` |
| TipoServicio | Hotel, Transporte en Bus, Transporte Ferroviario, Camping de Etapa | `servicio.tipo` |
| EstadoReserva | Pendiente, Confirmada, Sin Permiso, Cancelada, Finalizada | `reserva.estado` |
| EstadoSaldo | Adeudado, Abonado | `reserva.estado_saldo` |
| EstadoPermiso | Pendiente, Obtenido, No Obtenido | `excursionista.estado_permiso` |
| TipoPago | Seña, Saldo, Total | `pago.tipo_pago` |
| MotivoDevolucion | Reintegro, Reembolso | `devolucion.motivo` |
| CategoriaValoracion | Hotel, Camping de Etapa, Transporte en Bus, Transporte Ferroviario, Porteadores, Guía, Equipo de Camping | `detalle_valoracion.categoria` |

`pago.medio_pago` **no** es una enumeración: es el texto que informa la pasarela de pago.

## Detalle de las tablas

Los tipos, los valores por defecto y las reglas salen del diccionario de datos que el grupo armó el 19/09 al normalizar.
El Doc 6 entregado sólo lista las columnas; si la cátedra pide el diccionario, es éste.

Cómo se escribe cada tipo en una migración:

| Tipo | En la migración |
|---|---|
| BIGINT, clave primaria | `$table->id('id_reserva')` |
| BIGINT, clave foránea | `$table->foreignId('id_excursion')->constrained('excursion', 'id_excursion')` |
| VARCHAR(n) | `$table->string('nombre', 80)` |
| SMALLINT | `$table->smallInteger('cupo')` |
| NUMERIC(10,2) | `$table->decimal('monto', 10, 2)` |
| DATE | `$table->date('fecha')` |
| TIMESTAMP | `$table->dateTime('fecha_reserva')` |
| BOOLEAN | `$table->boolean('equipo_camping')` |
| Enumeración | `$table->enum('estado', ['Activo', 'Inactivo'])` |

En la base van las claves, los valores únicos, las enumeraciones, los nulos y los valores por defecto. Las reglas de
rango («mayor que 0», «de 1 a 5», «siempre lunes») las valida la aplicación antes de guardar.

### usuario

Usuarios internos: administradores y guías.

| Columna | Tipo | Nulo | Regla |
|---|---|---|---|
| `id_usuario` | BIGINT | No | Clave primaria, autoincremental |
| `correo` | VARCHAR(100) | No | Único. Con él se inicia sesión |
| `password` | VARCHAR(255) | No | Se guarda el hash, nunca el texto |
| `rol` | Enumeración Rol | No |  |

### guia

Datos propios de los usuarios con rol Guía.

| Columna | Tipo | Nulo | Regla |
|---|---|---|---|
| `id_usuario` | BIGINT | No | Clave primaria y foránea a `usuario`; no es autoincremental |
| `nombre` | VARCHAR(50) | No |  |
| `apellido` | VARCHAR(50) | No |  |

### recorrido

Rutas del Camino Inca. Dato precargado: el sistema no las crea ni las modifica.

| Columna | Tipo | Nulo | Regla |
|---|---|---|---|
| `id_recorrido` | BIGINT | No | Clave primaria, autoincremental |
| `nombre` | VARCHAR(60) | No | Único |
| `duracion_dias` | SMALLINT | No | Mayor que 0 |
| `cantidad_campings` | SMALLINT | No | Mayor o igual a 0 |

### etapa

Etapas ordenadas de cada recorrido. Dato precargado.

| Columna | Tipo | Nulo | Regla |
|---|---|---|---|
| `id_etapa` | BIGINT | No | Clave primaria, autoincremental |
| `id_recorrido` | BIGINT | No | Clave foránea a `recorrido` |
| `nombre` | VARCHAR(80) | No |  |
| `orden` | SMALLINT | No | Mayor o igual a 1. Único dentro del recorrido |

### servicio

Servicios que pueden incluirse en un paquete. Dato precargado.

| Columna | Tipo | Nulo | Regla |
|---|---|---|---|
| `id_servicio` | BIGINT | No | Clave primaria, autoincremental |
| `tipo` | Enumeración TipoServicio | No |  |
| `nombre` | VARCHAR(80) | No | Único junto con `tipo` |

### paquete

Oferta turística cerrada que arma el administrador.

| Columna | Tipo | Nulo | Regla |
|---|---|---|---|
| `id_paquete` | BIGINT | No | Clave primaria, autoincremental |
| `id_recorrido` | BIGINT | No | Clave foránea a `recorrido` |
| `nombre` | VARCHAR(80) | No | Único |
| `precio_base` | NUMERIC(10,2) | No | Mayor que 0. USD por persona |
| `costo_noche_extra_cusco` | NUMERIC(10,2) | No | Mayor o igual a 0. USD por noche |
| `costo_equipo_camping` | NUMERIC(10,2) | No | Mayor o igual a 0. USD por persona |
| `cantidad_porteadores` | SMALLINT | No | Mayor o igual a 0 |
| `estado` | Enumeración EstadoPaquete | No | Por defecto «Activo». La baja es lógica |
| `fecha_creacion` | DATE | No | Fecha del alta |

### paquete_servicio

Servicios incluidos en cada paquete. Tabla intermedia, sin modelo.

| Columna | Tipo | Nulo | Regla |
|---|---|---|---|
| `id_paquete` | BIGINT | No | Clave primaria (junto con `id_servicio`) y foránea a `paquete` |
| `id_servicio` | BIGINT | No | Clave primaria (junto con `id_paquete`) y foránea a `servicio` |

### excursion

Salida concreta de un paquete, con fecha, cupo y guía propios.

| Columna | Tipo | Nulo | Regla |
|---|---|---|---|
| `id_excursion` | BIGINT | No | Clave primaria, autoincremental |
| `id_paquete` | BIGINT | No | Clave foránea a `paquete`. Único junto con `fecha_salida` |
| `id_guia` | BIGINT | No | Clave foránea a `guia.id_usuario` |
| `id_etapa_actual` | BIGINT | Sí | Clave foránea a `etapa`. Nulo hasta que el guía reporta la primera etapa |
| `fecha_salida` | DATE | No | Siempre lunes |
| `cupo` | SMALLINT | No | Mayor que 0. Cupo total |
| `plazas_retenidas` | SMALLINT | No | Por defecto 0. Nunca negativa |
| `fecha_hora_inicio_recorrido` | TIMESTAMP | Sí | Nulo hasta que el guía inicia el recorrido |

### reserva

Reserva grupal de una excursión.

| Columna | Tipo | Nulo | Regla |
|---|---|---|---|
| `id_reserva` | BIGINT | No | Clave primaria, autoincremental |
| `id_excursion` | BIGINT | No | Clave foránea a `excursion` |
| `numero_reserva` | VARCHAR(12) | No | Único. Número secuencial más dígito verificador |
| `correo_electronico` | VARCHAR(100) | No | Correo del titular |
| `fecha_reserva` | TIMESTAMP | No | Momento en que se registra el primer pago |
| `estado` | Enumeración EstadoReserva | No | Por defecto «Pendiente» |
| `estado_saldo` | Enumeración EstadoSaldo | No | «Adeudado» si se pagó la seña; «Abonado» si se pagó el total |
| `noches_extra_antes` | SMALLINT | No | Mayor o igual a 0. Sumada a `noches_extra_despues`, a lo sumo 2 |
| `noches_extra_despues` | SMALLINT | No | Mayor o igual a 0 |
| `fecha_limite_saldo` | TIMESTAMP | Sí | Nulo si se abonó el total |
| `fecha_limite_confirmacion` | TIMESTAMP | No |  |

### excursionista

Integrantes de cada reserva.

| Columna | Tipo | Nulo | Regla |
|---|---|---|---|
| `id_excursionista` | BIGINT | No | Clave primaria, autoincremental |
| `id_reserva` | BIGINT | No | Clave foránea a `reserva` |
| `nombre` | VARCHAR(50) | No |  |
| `apellido` | VARCHAR(50) | No |  |
| `documento_pasaporte` | VARCHAR(20) | No | Único dentro de la reserva |
| `equipo_camping` | BOOLEAN | No | Por defecto falso |
| `estado_permiso` | Enumeración EstadoPermiso | No | Por defecto «Pendiente» |

### pago

Dinero ingresado por cada reserva.

| Columna | Tipo | Nulo | Regla |
|---|---|---|---|
| `id_pago` | BIGINT | No | Clave primaria, autoincremental |
| `id_reserva` | BIGINT | No | Clave foránea a `reserva`. Único junto con `tipo_pago` |
| `fecha` | DATE | No |  |
| `monto` | NUMERIC(10,2) | No | Mayor que 0. USD |
| `tipo_pago` | Enumeración TipoPago | No |  |
| `medio_pago` | VARCHAR(40) | No | Lo informa la pasarela de pago |

### comprobante

Constancia emitida por cada pago.

| Columna | Tipo | Nulo | Regla |
|---|---|---|---|
| `id_comprobante` | BIGINT | No | Clave primaria, autoincremental |
| `id_pago` | BIGINT | No | Clave foránea a `pago`. Único: un comprobante por pago |
| `numero_comprobante` | VARCHAR(20) | No | Único |
| `fecha_emision` | TIMESTAMP | No |  |

### devolucion

Dinero a devolver por la cancelación de una reserva. Se acredita por fuera del sistema.

| Columna | Tipo | Nulo | Regla |
|---|---|---|---|
| `id_devolucion` | BIGINT | No | Clave primaria, autoincremental |
| `id_reserva` | BIGINT | No | Clave foránea a `reserva`. Único: una devolución por reserva |
| `fecha` | DATE | No |  |
| `monto` | NUMERIC(10,2) | No | Mayor que 0. USD |
| `motivo` | Enumeración MotivoDevolucion | No | Reintegro: 100 % de lo abonado. Reembolso: 50 % |

### valoracion

Evaluación del viaje sobre una reserva finalizada.

| Columna | Tipo | Nulo | Regla |
|---|---|---|---|
| `id_valoracion` | BIGINT | No | Clave primaria, autoincremental |
| `id_reserva` | BIGINT | No | Clave foránea a `reserva`. Único: una valoración por reserva |
| `fecha` | TIMESTAMP | No |  |
| `puntaje_experiencia_general` | SMALLINT | No | De 1 a 5 |
| `comentario` | VARCHAR(500) | Sí | Opcional |

### detalle_valoracion

Puntaje de cada categoría dentro de una valoración.

| Columna | Tipo | Nulo | Regla |
|---|---|---|---|
| `id_valoracion` | BIGINT | No | Clave primaria (junto con `categoria`) y foránea a `valoracion` |
| `categoria` | Enumeración CategoriaValoracion | No | Clave primaria (junto con `id_valoracion`) |
| `puntaje` | SMALLINT | No | De 1 a 5 |

## Claves foráneas y borrado

| Columna | Referencia | Al borrar la fila referenciada |
|---|---|---|
| `guia.id_usuario` | `usuario.id_usuario` | Se borra en cascada |
| `etapa.id_recorrido` | `recorrido.id_recorrido` | Se borra en cascada |
| `paquete.id_recorrido` | `recorrido.id_recorrido` | Se impide el borrado |
| `paquete_servicio.id_paquete` | `paquete.id_paquete` | Se borra en cascada |
| `paquete_servicio.id_servicio` | `servicio.id_servicio` | Se impide el borrado |
| `excursion.id_paquete` | `paquete.id_paquete` | Se borra en cascada |
| `excursion.id_guia` | `guia.id_usuario` | Se impide el borrado |
| `excursion.id_etapa_actual` | `etapa.id_etapa` | Se impide el borrado |
| `reserva.id_excursion` | `excursion.id_excursion` | Se impide el borrado |
| `excursionista.id_reserva` | `reserva.id_reserva` | Se borra en cascada |
| `pago.id_reserva` | `reserva.id_reserva` | Se impide el borrado |
| `comprobante.id_pago` | `pago.id_pago` | Se borra en cascada |
| `devolucion.id_reserva` | `reserva.id_reserva` | Se impide el borrado |
| `valoracion.id_reserva` | `reserva.id_reserva` | Se impide el borrado |
| `detalle_valoracion.id_valoracion` | `valoracion.id_valoracion` | Se borra en cascada |

«En cascada» es `->cascadeOnDelete()`; «se impide» es `->restrictOnDelete()`. En el uso normal no se borra casi nada:
la baja de un paquete es lógica y las reservas pasan a «Cancelada» o «Finalizada».

## Valores únicos

- De una columna: `usuario.correo`, `recorrido.nombre`, `paquete.nombre`, `reserva.numero_reserva`,
  `comprobante.numero_comprobante`, `comprobante.id_pago`, `devolucion.id_reserva` y `valoracion.id_reserva`.
- De dos columnas: (`id_recorrido`, `orden`) en `etapa`, (`tipo`, `nombre`) en `servicio`, (`id_paquete`,
  `fecha_salida`) en `excursion`, (`id_reserva`, `documento_pasaporte`) en `excursionista` e (`id_reserva`,
  `tipo_pago`) en `pago`.

## Decisiones del TP5

Tomadas el 05/10. Cómo se escriben en el código está en [convenciones](convenciones-de-codigo.md).

1. **Nombres de tablas y claves: los del Doc 6.** Tablas en singular y minúscula (`reserva`) y clave primaria
   `id_<tabla>` (`id_reserva`); cada modelo declara `$table` y `$primaryKey`. Así la base coincide con la documentación
   que revisa la cátedra.
2. **Tabla de usuarios: USUARIO reemplaza a `users`.** Columnas `correo`, `password` y `rol`; el modelo es `Usuario`. El
   panel de Filament inicia sesión por `correo` y no ofrece «Recordarme», porque no hay columna `remember_token`. No se
   crea `password_reset_tokens`. Las cuentas de administrador y de guía se cargan con los *seeders*.
3. **Sin fechas de auditoría.** Ninguna tabla lleva `created_at` ni `updated_at`: los modelos declaran
   `$timestamps = false`.

Las tablas `sessions`, `cache`, `jobs` y `migrations` son de Laravel: no forman parte del modelo de datos y se dejan
como vienen.

## Pendiente

**Retención de cupo.** Dónde se guarda la hora de inicio de cada retención y cómo se asegura la concurrencia
(ver [reparto](reparto-casos-de-uso.md), pendientes del Área B). Se consulta a la cátedra el 09/10.
