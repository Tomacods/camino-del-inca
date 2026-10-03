# Esquema de la base de datos

Las 15 tablas del **Documento de Normalización de Base de Datos** (Doc 6), con la corrección de la devolución del
02/10: se eliminó `RETENCION_CUPO` y `EXCURSION` sumó `plazas_retenidas`.

Es la referencia para escribir las migraciones y los modelos en el TP5. **El documento manda:** si al programar hace
falta cambiar una tabla, se cambia también el Doc 6.

**Clave primaria** en negrita · *clave foránea* en cursiva · ***clave primaria que también es foránea*** en ambas.

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

## Decisiones para tomar al empezar el TP5

Son del grupo; conviene cerrarlas antes de escribir la primera migración.

1. **Nombres de tablas y claves.** Laravel espera tablas en plural y clave `id` (`reservas.id`); el Doc 6 usa singular y
   `id_<tabla>` (`RESERVA.id_reserva`). Se puede mantener lo del Doc 6 declarando `$table` y `$primaryKey` en cada
   modelo, y así la base coincide con la documentación que revisa la cátedra. Lo que se elija, igual en las 15 tablas.
2. **Tabla de usuarios.** El proyecto base de Laravel trae su propia tabla `users` (con `name` y `email`) y Filament la
   usa para el inicio de sesión. Hay que reemplazarla por USUARIO (`correo`, `password`, `rol`) y adaptar el inicio de
   sesión del panel a esos nombres. Revisar en la documentación de Filament cómo se cambia el campo de acceso y de
   dónde toma el nombre que muestra.
3. **Fechas de auditoría.** Eloquent agrega `created_at` y `updated_at` salvo que el modelo declare
   `$timestamps = false`. El Doc 6 no las tiene.
4. **Retención de cupo.** Dónde se guarda la hora de inicio de cada retención y cómo se asegura la concurrencia
   (ver [reparto](reparto-casos-de-uso.md), pendientes del Área B).
