# Cómo trabajamos

Somos cuatro programando sobre el mismo código. Estas reglas existen para no perder trabajo y para tener siempre una
versión que funcione, que es lo que pidió la cátedra en la devolución del 02/10.

## Las cinco reglas

1. **`main` siempre funciona.** Nadie hace `push` directo a `main`: todo entra por *pull request*.
2. **Una rama por caso de uso** (o por arreglo). Ramas cortas, que se integran en días y no en semanas.
3. **Otro integrante revisa** el *pull request* antes de integrarlo.
4. **Una migración que ya está en `main` no se edita**: se crea otra que la modifique.
5. **Cada viernes: versión estable etiquetada y respaldo de la base.**

## Antes de empezar (una vez por PC)

Cada uno hace los commits con su nombre, para que quede registrado quién programó qué:

```bash
git config user.name  "Nombre Apellido"
git config user.email "el-correo-de-tu-cuenta-de-github"
```

## El ciclo de trabajo

```bash
# 1. Arrancar desde main actualizado
git switch main
git pull

# 2. Crear la rama del caso de uso
git switch -c cu-14-realizar-reserva

# 3. Programar y hacer commits chicos
git add -A
git commit -m "CU-14: retiene el cupo al pasar al pago"

# 4. Subir la rama y abrir el pull request en GitHub
git push -u origin cu-14-realizar-reserva
```

Si la rama dura más de un día, traer lo nuevo de `main` antes de seguir:

```bash
git switch main && git pull
git switch cu-14-realizar-reserva
git merge main
```

### Nombres de ramas

| Para | Formato | Ejemplo |
|---|---|---|
| Un caso de uso | `cu-NN-nombre` | `cu-14-realizar-reserva` |
| La base compartida | `base-tema` | `base-migraciones-reserva` |
| Un arreglo | `arreglo-tema` | `arreglo-calculo-cupo` |
| Documentación | `docs-tema` | `docs-reparto` |

### Mensajes de commit

Empiezan con el caso de uso (o `Base`, `Arreglo`, `Docs`) y dicen qué hace el cambio:

```
CU-15: registra el pago y libera el cupo retenido
Base: migración y modelo de Excursión
Arreglo: el cupo disponible no descontaba las plazas retenidas
```

## Antes de abrir un pull request

- `php artisan migrate:fresh --seed` corre sin errores.
- `php artisan test` pasa.
- `./vendor/bin/pint` aplicado (formato del código).
- Probaste a mano el caso de uso completo, incluidos los cursos alternativos.
- No subís `.env`, credenciales ni respaldos.

El que revisa baja la rama, corre lo mismo y prueba el caso de uso. Si funciona, aprueba y se integra. El que abrió el
*pull request* es quien lo integra y borra la rama.

## Base de datos

- El esquema sale de las **migraciones**; los datos de prueba, de los ***seeders***. Nadie crea tablas ni columnas a mano
  en pgAdmin.
- **Mientras se arma la base (TP5, hasta la etiqueta `v0.1`)** las migraciones se pueden corregir, y todos reconstruyen
  con `php artisan migrate:fresh --seed`. **Después de `v0.1`**, cada cambio de esquema es una migración nueva.
- Los cambios de esquema los integra **una sola persona** (el responsable de la base, ver
  [docs/reparto-casos-de-uso.md](docs/reparto-casos-de-uso.md)). Si tu caso de uso necesita una columna, avisale.
- El esquema tiene que coincidir con el Documento de Normalización
  ([docs/esquema-base-de-datos.md](docs/esquema-base-de-datos.md)). Si cambia uno, cambia el otro.

## Dependencias

- `composer.lock` y `package-lock.json` **se suben**. Así todos tenemos las mismas versiones.
- Un paquete nuevo se agrega con `composer require` o `npm install <paquete>` en una rama propia y se avisa al grupo.
- Si hay conflicto en un archivo `.lock`, no se resuelve a mano: se toma el de `main` y se vuelve a correr el comando.
- Después de un `git pull` que trae cambios: `composer install`, `npm install` y, hasta la etiqueta `v0.1`,
  `php artisan migrate:fresh --seed`. A partir de `v0.1`, `php artisan migrate`.

## Versiones estables y respaldos

Cada viernes, con `main` funcionando:

```bash
git switch main && git pull
git tag -a v0.2 -m "Reserva y pago de seña funcionando"
git push origin v0.2

bash scripts/respaldar-bd.sh v0.2
```

El respaldo queda en `respaldos/` (no se sube al repositorio) y se copia al Drive del grupo. Para volver a un respaldo:

```bash
psql -U postgres -d camino_del_inca -f respaldos/<archivo>.sql
```

El respaldo repone las tablas que tenía. Si después se crearon tablas nuevas, quedan: para volver exactamente a ese
punto, borrar la base, crearla vacía y recién ahí restaurar.

Para volver a ver el código de una versión: `git switch --detach v0.2`.

Es preferible una versión estable a la que le falta algo antes que una completa que no anda. Las funciones del nudo
del problema (listar y consultar paquetes, reservar, pagar, notificar) tienen que funcionar en todas las etiquetas a
partir de la primera en que estén.

## Qué no se sube nunca

`.env`, credenciales de Mercado Pago o de Mailtrap, `vendor/`, `node_modules/`, respaldos de la base y archivos
personales del editor. Ya están en `.gitignore`; si Git ofrece subir alguno, algo está mal.
