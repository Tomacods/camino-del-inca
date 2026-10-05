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

## Restricciones

- Valores únicos: `RESERVA.numero_reserva`, `PAQUETE.nombre`, `COMPROBANTE.numero_comprobante`,
  (`id_paquete`, `fecha_salida`) en EXCURSION y (`id_reserva`, `tipo_pago`) en PAGO.
- Las enumeraciones (`rol`, `estado`, `estado_saldo`, `estado_permiso`, `tipo_pago`, `medio_pago`, `motivo`, `tipo`,
  `categoria`) son columnas con dominio cerrado: los valores son los del diagrama de clases del Doc 3.
- `plazas_retenidas` arranca en 0 y nunca es negativa.

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
