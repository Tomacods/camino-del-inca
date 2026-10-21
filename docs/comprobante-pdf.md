# Comprobante de pago en PDF — CU-15, CU-16 y CU-13

Punto 5.1 del plan de arreglos. El comprobante se descarga desde las pantallas de reserva registrada y saldo pagado,
sin una ruta nueva. Usa el pago que muestra cada pantalla y sus controles de sesión. En CU-16 también se comprueba
que el número de reserva guardado en `acceso_reserva` coincida con el de la pantalla.

El modelo prepara los datos y genera el PDF A4, sin imágenes y con DejaVu Sans. El saldo del comprobante resta solamente
los pagos de esa reserva con `id_pago` menor o igual al del pago elegido: pagar el saldo no modifica el saldo informado
en el comprobante de la seña. Los montos se muestran en USD con dos decimales.

Los correos «Primera Compra» y «Pago de Saldo» adjuntan el comprobante del último pago. Los datos se guardan al invocar
la notificación y el PDF se genera al enviar el correo desde la cola. Los otros seis tipos no llevan adjunto.

## Cómo probarlo a mano

Preparación: instalar las dependencias con `composer install`, verificar `dom` y `mbstring` con `php -m`, compilar
con `npm run build` y levantar el servidor, la cola y el túnel según
[la guía de instalación](instalacion-windows.md#8-ngrok-para-probar-el-pago-y-para-la-presentación).
Usar credenciales, comprador y tarjeta **de prueba** de Mercado Pago.

### CU-15 — nueva reserva

1. Entrar siempre por la dirección HTTPS del túnel. Elegir un paquete con salida disponible y completar una reserva
   con integrantes y noches extra. Elegir seña o total.
2. Pagar en Mercado Pago y volver al sitio. En «Reserva registrada», pulsar «Descargar comprobante» en la tarjeta
   «Comprobante».
3. Verificar el nombre `comprobante-<numero_comprobante>.pdf` y abrirlo: número y emisión, reserva y correo, excursión,
   paquete, salida, duración, servicios, noches, integrantes, precio final, tipo/medio/monto del pago y saldo después
   del pago. Revisar tildes, ñ, comillas, importes y que no haya texto cortado.
4. Comprobar el correo «Primera Compra»: incluye la línea del adjunto y el PDF con el mismo nombre y datos.
5. Repetir con el otro tipo de pago inicial. En una sesión sin reserva registrada, la pantalla no ofrece la descarga.

### CU-16 — saldo de la reserva de prueba

1. Con los seeders cargados, consultar «Mi reserva» con `federico.rios@mail.com` y `000125-9`. Tiene estado
   «Confirmada», seña de USD 750,00, total de USD 1.500,00 y saldo de USD 750,00. El plazo vence el 01/01/2027 a las
   23:59:59; realizar la prueba antes de ese momento.
2. Elegir «Pagar saldo», aprobar el pago de prueba en Mercado Pago y volver al sitio.
3. En «Saldo pagado», descargar y abrir el PDF. Debe corresponder al nuevo pago «Saldo», con su número de comprobante,
   monto USD 750,00 y saldo posterior USD 0,00, en vez del comprobante de la seña.
4. Comprobar el correo «Pago de Saldo» y su adjunto. Sin acceso a esa reserva, o con el acceso de otra reserva, la
   pantalla no debe mostrar ni permitir descargar sus datos.

Usar un pago nuevo por cada repetición. El botón descarga desde la sesión del cliente que volvió de Mercado Pago;
no se agrega una dirección pública al comprobante.

## Diferencias con el diseño para actualizar

### Métodos

| Clase o componente | Método nuevo | Responsabilidad |
|---|---|---|
| `Reserva` | `getDatosComprobante(Pago $pago): array` | Reúne los datos y calcula el saldo posterior a ese pago |
| `Reserva` | `generarComprobantePdf(Pago $pago): string` | Devuelve el contenido PDF para descargar |
| `Reserva` | `armarComprobantePdf(array $datos): string` (estático) | Genera el PDF con datos ya reunidos, también para el adjunto en cola |
| `reserva-confirmada` | `descargarComprobante(): ?StreamedResponse` | Envuelve el PDF del pago mostrado en una descarga, sin parámetros |
| `saldo-pagado` | `descargarComprobante(): ?StreamedResponse` | Descarga el comprobante del pago «Saldo», sin parámetros |
| `NotificacionCliente` | `attachments(): array` | Implementa el adjunto diferido de Laravel para los dos tipos indicados |

Se reutilizan `Comprobante::getDatos()`, `Pago::getPago()`, `Paquete::getDatosComprobante()` y
`Reserva::calcularMontoTotal()`. No hay tablas, columnas, rutas ni variables de entorno nuevas.

### Dependencias

`barryvdh/laravel-dompdf` v3.1.2, con `dompdf/dompdf` v3.1.6, `dompdf/php-font-lib` 1.0.2,
`dompdf/php-svg-lib` 1.0.2, `masterminds/html5` 2.11.0 y `sabberworm/php-css-parser` v9.5.0.
El lock agrega esos seis paquetes y conserva las versiones anteriores. La fuente se incorpora sólo con los glifos
utilizados para reducir el tamaño de los archivos y el consumo de memoria en envíos sucesivos.

### Doc 3 y prototipo

- **CU-15, paso 11:** incluir la descarga del comprobante PDF desde «Reserva registrada», con los datos especificados
  y el saldo correspondiente a ese pago. Agregar los métodos nuevos a las clases y al flujo de descarga del diagrama
  de secuencia.
- **CU-16, paso 10:** incluir la descarga del comprobante del pago «Saldo» desde «Saldo pagado», con el control de
  acceso de la reserva. Reflejar la acción en su diagrama de secuencia.
- **CU-13, A1:** indicar que «Primera Compra» y «Pago de Saldo» adjuntan el PDF del último pago y avisan en el cuerpo.
  Documentar que los datos se reúnen al invocar la notificación y el adjunto se genera al enviar desde la cola;
  reflejarlo en el DS-13 y la descripción de `NotificacionCliente`.
- **Prototipo:** incorporar «Descargar comprobante» en la tarjeta del comprobante de ambas pantallas, el estado
  «Preparando comprobante…» y el formato PDF A4. En los dos correos, incorporar «Te adjuntamos el comprobante en PDF.»

Los documentos de Drive y el prototipo quedan pendientes de actualización por el grupo.

## Verificación de esta implementación

- `php artisan migrate:fresh --seed`: pasó sobre SQLite temporal separada de PostgreSQL.
- `php artisan test`: 595 pruebas y 2628 aserciones; pasó con el límite de memoria PHP de 128 MB.
- `./vendor/bin/pint`: pasó; `git diff --check` sin errores.
- Descargas en navegador local desde ambas pantallas: nombres correctos, PDF válidos, sin errores JavaScript.
- Escritorio y móvil de 390 px: botones visibles, sin desbordamiento horizontal.
- Ambos PDF A4 abiertos como imágenes: tildes, ñ, comillas, montos y contenido legibles, sin cortes.

Esta verificación usó seeders y sesiones locales de QA. **Falta probar a mano el recorrido completo con el túnel,
Mercado Pago y el servicio de correo.** La casilla «Probé a mano» del PR queda sin marcar.
