# Métodos de Valoracion incorporados con CU-23

Verificación del 23/10/2026 sobre `fb0c476` de `origin/main`. El PR [#56](https://github.com/Tomacods/camino-del-inca/pull/56)
se integró por squash como `2bf81e3`; sus archivos de CU-23 coinciden con la revisión `6de3803`, que corrige los
nombres accesibles de las estrellas. La rama local de esta verificación es `docs-cu23-verificacion`.

La ERS vigente, R-23, y la descripción de CU-23 en el Doc 3 permiten valorar los servicios de una reserva finalizada.
El pendiente del PR era llevar los tres métodos nuevos al diagrama de clases. No se agregaron reglas, atributos,
tablas ni columnas.

## Firmas del código actual

Los tres métodos de `app/Models/Valoracion.php` son **públicos y estáticos**:

```php
public static function admiteValoracion(Reserva $reserva): bool
public static function getCategoriasAValorar(Reserva $reserva): array
public static function valorarServicios(Reserva $reserva, array $puntajes, int $experienciaGeneral, ?string $comentario): self
```

En la notación del diagrama, `+` indica visibilidad pública y el subrayado indica que la operación es estática.
Se mantuvieron los nombres de parámetros y tipos del código, incluidos `?string` y `self`.
`getCategoriasAValorar()` devuelve un arreglo de casos de `CategoriaValoracion`; `self` representa `Valoracion`.

## Archivos actualizados

En `F:\downloads\Entrega Grupo 11\Revisiones de código vs diseño`:

- `DrawIO\Diagrama de clases de diseño.drawio`: operaciones de `Valoracion`, con espacio para sus firmas completas.
- `Png\Diagrama de clases de diseño.png`: representación regenerada desde el editable.
- `3 - Modelo de Dominio y de Casos de Uso - Grupo 11.docx`: reemplazo de la imagen de la Figura 1, página 4.

La Figura 1 conserva su leyenda existente. Se ajustaron el tamaño de la caja y la disposición de sus asociaciones,
`DetalleValoracion` y `ServicioCorreo` para evitar superposiciones, sin cambiar relaciones ni multiplicidades.
En el DOCX sólo cambió `word/media/image35.png`: se preservaron el XML, las relaciones, los textos, los comentarios
y el control de cambios anterior. Los originales están respaldados en `CU-23\originales`.

## Fuentes verificadas

- [ERS del grupo](https://docs.google.com/document/d/1JNYedQn15W8otheXpB3ZSXBlo-mCQAar/edit), requisito R-23.
- [Doc 3 vigente en Revisiones de código vs diseño](https://docs.google.com/document/d/17iZ6LTbCxcEfVidGTWvr20ESbPl0Ngq3/edit),
  descripción de CU-23 y Figura 1.
- [Diagrama editable de esa carpeta](https://drive.google.com/file/d/1imEManyjmU9xitJCDLPFG7ADzOiJ0daj/view).

Antes de editar se comprobó que el DOCX local y el descargado de Drive tenían la misma huella SHA-256
(`e8b24df7f4e18268b9f23d39d17c5de8eac59984a79654a57597d00d4216f9fc`), y que los bytes del editable local coincidían
con el de Drive.

La actualización se sincronizó sobre los mismos archivos de Drive, sin duplicarlos ni moverlos. La descarga posterior
del Doc 3 coincide con el archivo final local: SHA-256
`24feb98264251fa22313630ed2d32f6f502810d31244aa02b8fd2d4a9c34a84e`.
También se verificó la igualdad de bytes del DrawIO actualizado con la lectura posterior de Drive.
