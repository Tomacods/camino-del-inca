# Camino del Inca — guía para Claude Code

Sistema web de reservas de excursiones al Camino del Inca. Proyecto de la cátedra Desarrollo de Software (UNPSJB, 2026),
Grupo 11. Laravel 12, Filament 5 (panel del administrador y del guía), Blade y Livewire 4 (portal del cliente) y
PostgreSQL. Se trabaja en Windows, con los comandos desde Git Bash.

## Antes de tocar código

Leer, en este orden:

1. `CONTRIBUTING.md` — ramas, commits, *pull requests* y migraciones.
2. `docs/convenciones-de-codigo.md` — nombres, dónde va cada cosa y reglas de la base y de los modelos.
3. `docs/esquema-base-de-datos.md` — las 15 tablas y sus columnas. Es la referencia de todas las migraciones.
4. `docs/reparto-casos-de-uso.md` — de quién es cada caso de uso y en qué orden se hacen.

## Reglas que no se rompen

- **Nunca hacer commit ni push sobre `main`.** Si la rama actual es `main`, crear primero la que corresponda
  (`cu-NN-nombre`, `base-tema`, `arreglo-tema` o `docs-tema`).
- **No hacer `git push` ni abrir un *pull request* sin que te lo pidan.**
- **El esquema sale de `docs/esquema-base-de-datos.md`.** No agregar, quitar ni renombrar tablas o columnas por cuenta
  propia: si hace falta un cambio, frenar y avisar, porque también cambia el Documento de Normalización.
- Tablas en singular y minúscula, clave primaria `id_<tabla>`, sin `created_at` ni `updated_at`. El detalle está en las
  convenciones.
- No modificar `.env` ni mostrar su contenido. Las credenciales se leen a través de `config/`.
- No instalar paquetes de Composer o de npm sin avisar.

## Cómo se escribe el código

- Nombres en español, iguales a los del diseño: clases como en el diagrama de clases, métodos como en los diagramas de
  secuencia, columnas como en el esquema.
- Modelo–Vista–Controlador sin capa de servicios: las reglas de negocio van en los modelos; los controladores y los
  componentes Livewire sólo validan el formato y coordinan.
- Código simple y legible. El grupo tiene que poder explicar cada línea en la evaluación: nada de abstracciones que el
  caso de uso no pida.
- Los textos que ve el usuario, en español.

## Lo que no está en este repositorio

La ERS, el modelo de dominio, los casos de uso y los diagramas están en el Drive del grupo. Si falta un dato del diseño
(un valor de una enumeración, un paso de un caso de uso, una regla de negocio), **preguntar antes de inventarlo**.

## Antes de dar algo por terminado

```bash
php artisan migrate:fresh --seed
php artisan test
./vendor/bin/pint
```

Los tres tienen que pasar. Después, contar qué se cambió y qué falta probar a mano.
