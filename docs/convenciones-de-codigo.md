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
- **Tareas programadas** — las cancelaciones automáticas (CU-22 y CU-26).

Regla práctica: si una cuenta o una condición del negocio aparece en un controlador o en una vista, va en el modelo.

## Formato

- El formato lo aplica **Pint**, que viene con Laravel: `./vendor/bin/pint` antes de cada *pull request*. No se discute
  el estilo a mano.
- Un método hace una sola cosa. Si necesita un comentario para explicar *qué* hace, le falta un nombre mejor; los
  comentarios son para explicar *por qué*.
- Sin valores sueltos en el código: los 5 minutos de retención, los porcentajes de devolución y los plazos van en
  constantes con nombre o en un archivo de `config/`.
- Los textos que ve el usuario, en español.

## Datos y seguridad

- Las operaciones que tocan varias tablas (confirmar un pago, cancelar una reserva) van dentro de una transacción.
- Nada de credenciales en el código: se leen del `.env` a través de `config/`.
- El sistema no guarda datos de tarjetas: el cobro lo resuelve la pasarela.
- Toda entrada del usuario se valida en el servidor, aunque la pantalla ya la valide.

## Pruebas

Cada caso de uso se prueba a mano con su curso normal y sus cursos alternativos antes del *pull request*, y esos
casos se anotan: son la base del documento de prueba del TP7.
