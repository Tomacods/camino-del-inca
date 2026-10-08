# Convenciones de código

Punto de partida para que el código de los cuatro se lea igual. Se revisa después de la clase de codificación de la
cátedra (14/10).

## El código sigue al diseño

El diseño ya está hecho (Doc 3: diagrama de clases y diagramas de secuencia; Doc 5: arquitectura). El código usa **los
mismos nombres**, para que se pueda ir de un diagrama a su clase sin traducir.

| Qué | Cómo se nombra | Ejemplo |
|---|---|---|
| Clases del dominio (modelos) | Como en el diagrama de clases | `Excursion`, `Reserva`, `Excursionista` |
| Métodos | Como en los diagramas de secuencia, en *camelCase* | `obtenerCupoDisponible()`, `retenerCupo()`, `liberarCupoRetenido()` |
| Tablas | Como en el Doc 6, en singular y minúscula | `reserva`, `paquete_servicio` |
| Columnas | Como en el Doc 6, en *snake_case* | `plazas_retenidas`, `fecha_limite_saldo` |
| Variables | En español, sin abreviar, que digan qué contienen | `$cantidadPlazas`, no `$cp` ni `$data` |
| Controladores | Como en los diagramas de secuencia | definir uno de los dos: `ControladorReserva` o `ReservaController` |

Si al programar hace falta un método o un atributo que no está en el diseño, se agrega y **se anota**, para llevarlo a
los documentos antes de la entrega del 09/11.

## Dónde va cada cosa

La arquitectura es Modelo–Vista–Controlador, sin capa de servicios:

- **Modelos (`app/Models`)** — las reglas de negocio, en la clase que tiene los datos para resolverlas (experto en
  información): la excursión calcula su cupo disponible y retiene y libera plazas; la reserva resuelve sus cambios de
  estado, sus fechas límite, su saldo y sus devoluciones; el paquete calcula el monto de la reserva.
- **Controladores y componentes Livewire** — reciben lo que hace el usuario, validan el formato de los datos y
  coordinan a los modelos. No tienen reglas de negocio. Desde acá se llama a Mercado Pago y se envían los correos.
- **Vistas** — sólo muestran. No consultan la base ni calculan.
- **Panel (`app/Filament`)** — pantallas del administrador y del guía.
- **Tareas (`app/Jobs`)** — lo que corre en la cola, fuera del pedido del cliente: por ejemplo, liberar el cupo
  retenido cuando vence el plazo (CU-14). Una tarea que descuenta o libera algo tiene que poder llegar dos veces sin
  descontar dos veces.
- **Tareas programadas** — las cancelaciones automáticas (CU-22 y CU-26).

Regla práctica: si una cuenta o una condición del negocio aparece en un controlador o en una vista, va en el modelo.

## Base de datos y modelos

Decidido el 05/10: la base se llama igual que el Documento de Normalización (Doc 6), no como propone Laravel.

- **Tablas** en singular y minúscula: `reserva`, `excursion`, `paquete_servicio`.
- **Clave primaria** `id_<tabla>`; las **claves foráneas** llevan el nombre que tienen en el esquema.
- **Sin `created_at` ni `updated_at`**: las migraciones no llevan `$table->timestamps()`.

Cada modelo lo declara, y cada relación escribe sus claves, porque Laravel no las deduce con estos nombres:

```php
class Reserva extends Model
{
    protected $table = 'reserva';

    protected $primaryKey = 'id_reserva';

    public $timestamps = false;

    public function excursion(): BelongsTo
    {
        return $this->belongsTo(Excursion::class, 'id_excursion', 'id_excursion');
    }

    public function excursionistas(): HasMany
    {
        return $this->hasMany(Excursionista::class, 'id_reserva', 'id_reserva');
    }
}
```

En la migración:

```php
Schema::create('reserva', function (Blueprint $table) {
    $table->id('id_reserva');
    $table->foreignId('id_excursion')->constrained('excursion', 'id_excursion');
    $table->string('numero_reserva')->unique();
    // ... el resto de las columnas del esquema
});
```

Tres tablas no tienen una clave propia de ese tipo:

- `guia`: su clave es `id_usuario`, que viene de `usuario`. El modelo lleva `public $incrementing = false;`.
- `paquete_servicio`: tabla intermedia, sin modelo. Se usa desde `Paquete` con
  `belongsToMany(Servicio::class, 'paquete_servicio', 'id_paquete', 'id_servicio')`.
- `detalle_valoracion`: clave compuesta (`id_valoracion`, `categoria`), que Eloquent no maneja. Se crea y se lee siempre
  a través de la valoración, nunca por su clave.

### Enumeraciones

Las nueve enumeraciones del [esquema](esquema-base-de-datos.md#enumeraciones) son enums de PHP en `app/Enums`, con los
valores exactos del esquema: `Rol`, `EstadoPaquete`, `TipoServicio`, `EstadoReserva`, `EstadoSaldo`, `EstadoPermiso`,
`TipoPago`, `MotivoDevolucion` y `CategoriaValoracion`.

- Los valores **siempre se escriben con el enum**, nunca como texto suelto: `EstadoReserva::SinPermiso`, no
  `'Sin Permiso'`. Vale para modelos, *seeders*, pruebas, componentes, vistas y Filament.
- Cada modelo convierte su columna al enum en `casts()`, así que al leerla se obtiene el enum y se compara con `===`:

  ```php
  protected function casts(): array
  {
      return [
          'estado' => EstadoReserva::class,
      ];
  }

  if ($reserva->estado === EstadoReserva::Confirmada) { ... }
  ```

- Para mostrar el texto en una vista: `{{ $reserva->estado->value }}`.
- Un enum no puede ser clave de un arreglo: ahí se usa su valor (`TipoServicio::Hotel->value => [...]`).
- Las migraciones siguen listando los valores en `$table->enum(...)`, como pide el esquema.

## Formato

- El formato lo aplica **Pint**, que viene con Laravel: `./vendor/bin/pint` antes de cada *pull request*. No se discute
  el estilo a mano.
- Un método hace una sola cosa. Si necesita un comentario para explicar *qué* hace, le falta un nombre mejor; los
  comentarios son para explicar *por qué*.
- Sin valores sueltos en el código: los 5 minutos de retención, los porcentajes de devolución y los plazos van en
  constantes con nombre o en un archivo de `config/`.
- Los parámetros de las reservas están en un solo archivo, `config/reserva.php` (anticipación mínima para reservar,
  días de anticipación y porcentaje del reembolso), y se leen con `config('reserva.<clave>')`. Un parámetro nuevo de
  reservas se agrega ahí, con su comentario.
- Los textos que ve el usuario, en español.

## Datos y seguridad

- Las operaciones que tocan varias tablas (confirmar un pago, cancelar una reserva) van dentro de una transacción.
- Nada de credenciales en el código: se leen del `.env` a través de `config/`.
- El sistema no guarda datos de tarjetas: el cobro lo resuelve la pasarela.
- Toda entrada del usuario se valida en el servidor, aunque la pantalla ya la valide.
- Todas las fechas y horas del sistema usan la zona horaria `America/Argentina/Buenos_Aires` (`timezone` en
  `config/app.php`).

## Pruebas

Cada caso de uso se prueba a mano con su curso normal y sus cursos alternativos antes del *pull request*, y esos
casos se anotan: son la base del documento de prueba del TP7.
