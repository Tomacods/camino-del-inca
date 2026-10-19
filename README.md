# Camino del Inca

Sistema web de reservas de excursiones al Camino del Inca: el cliente consulta los paquetes, reserva, paga la seña y el
saldo y valora el viaje; el administrador gestiona paquetes, excursiones, permisos y reservas; el guía reporta el avance
del recorrido.

Proyecto de la cátedra **Desarrollo de Software** (UNPSJB, 2026) — **Grupo 11**: Tomás Da Silva, César Millavanque,
Mora Anabella Molina y Mariano Reyes.

## Con qué está hecho

| Parte | Tecnología |
|---|---|
| Lógica de negocio | Laravel 12 (PHP), patrón Modelo–Vista–Controlador |
| Portal del cliente | Vistas Blade y componentes Livewire 4 |
| Panel del administrador y del guía | Filament 5 |
| Acceso a datos | Eloquent (Active Record) y migraciones |
| Base de datos | PostgreSQL |
| Cobro | Mercado Pago, Checkout Pro en modo de prueba |
| Correo | SMTP; Mailtrap durante las pruebas |

## Requisitos

- **PHP 8.2 o superior** (probado con 8.5), con las extensiones `pdo_pgsql`, `pgsql`, `intl`, `zip`, `mbstring`,
  `fileinfo`, `curl` y `openssl`
- **Composer 2**
- **Node.js 22 o superior** (probado con 24), con npm
- **PostgreSQL**
- **Git**. En Windows, los comandos de este repositorio se corren desde **Git Bash**.

**Si no tenés nada de esto instalado, seguí [docs/instalacion-windows.md](docs/instalacion-windows.md)**: tiene los
pasos en orden y los problemas que ya nos pasaron (el Control inteligente de aplicaciones de Windows bloquea PHP, y el
instalador de PostgreSQL a veces no crea el servicio).

Para comprobar que está todo: `php -v`, `composer -V` y `node -v` tienen que responder con su versión.

## Para clonar y levantar el proyecto

```bash
git clone https://github.com/Tomacods/camino-del-inca.git
cd camino-del-inca

composer install
npm install

cp .env.example .env
php artisan key:generate
```

Después:

1. Crear en PostgreSQL una base vacía llamada `camino_del_inca` (con pgAdmin, o con `createdb -U postgres camino_del_inca`).
2. En `.env`, completar `DB_PASSWORD` con la contraseña de tu PostgreSQL.
3. Crear las tablas y cargar los datos de prueba:

   ```bash
   php artisan migrate:fresh --seed
   ```

   El comando borra todas las tablas de la base y las vuelve a crear, así que sirve tanto la primera vez como si la
   base ya tenía tablas de una versión anterior.

4. Entrar al panel con una de las cuentas de prueba que cargan los *seeders* (`database/seeders/UsuarioSeeder.php`):

   | Rol | Correo | Contraseña |
   |---|---|---|
   | Administrador | `admin@caminodelinca.test` | `admin1234` |
   | Guía | `guia@caminodelinca.test` | `guia1234` |

   Son sólo para probar en tu PC. No hace falta crear usuarios a mano: `php artisan make:filament-user` no sirve, porque
   la tabla `usuario` no tiene las columnas que usa Filament por defecto.

5. Levantar la aplicación, en cuatro ventanas de Git Bash:

   ```bash
   php artisan serve           # http://127.0.0.1:8000   (panel: http://127.0.0.1:8000/admin)
   npm run dev                 # recompila los estilos mientras se trabaja
   php artisan queue:work      # corre las tareas de la cola
   php artisan schedule:work   # corre las tareas programadas: CU-22 y CU-26
   ```

   El sitio se abre en `http://127.0.0.1:8000` y no en `localhost:8000`: en Windows, `localhost` apunta primero a la
   dirección IPv6 (`::1`), donde `php artisan serve` no escucha, y cada pedido puede tardar unos 300 ms de más.

   Sin `php artisan queue:work`, las retenciones de cupo que el cliente abandona no se liberan: las plazas quedan
   retenidas hasta que se levante la cola. Si cambiás el código de una tarea (`app/Jobs`), cortá `queue:work` con
   Ctrl+C y volvé a levantarlo, porque guarda el código en memoria.

   Sin `php artisan schedule:work`, las cancelaciones automáticas (actor Temporizador) nunca se hacen: ni las de las
   reservas confirmadas que no pagaron el saldo a tiempo (CU-22) ni las de las pendientes o sin permiso cuya fecha
   límite de confirmación venció (CU-26). CU-22 corre a las 00:05, y CU-26, a los 5 minutos de cada hora.
   `php artisan schedule:list` muestra las tareas programadas y cuándo corre cada una.

   Para probarlas sin esperar, se corren a mano. Cancelan lo que esté vencido en ese momento, en la base de
   tu PC; los correos salen con `queue:work`, como los demás:

   ```bash
   php artisan reservas:cancelar-por-falta-de-pago            # CU-22
   php artisan reservas:cancelar-por-falta-de-confirmacion   # CU-26
   ```

Si al abrir el sitio aparece *Connection refused* en el puerto 5432, PostgreSQL está detenido: ver
[Problemas conocidos](docs/instalacion-windows.md#problemas-conocidos) en la guía de instalación.

El archivo `.env` es de cada uno y no se sube. Si se agrega una variable nueva, se agrega también en `.env.example`.

Después de cada `git pull` que traiga cambios: `composer install`, `npm install` y
`php artisan migrate:fresh --seed`.

Mientras se arma la base (hasta la etiqueta `v0.1`) las migraciones se corrigen y se renombran, y `php artisan migrate`
solo falla o deja la base a medias: por eso se reconstruye entera. Se pierden los datos cargados a mano; los de prueba
los repone el *seeder*. A partir de `v0.1` alcanza con `php artisan migrate`.

## Probar el pago con Mercado Pago

Lo necesita quien pruebe el pago de la reserva (CU-15) y la PC con la que se muestre el sistema en la presentación. El
resto del sistema funciona sin esto.

1. En `.env`, completar `MERCADOPAGO_ACCESS_TOKEN` con el *Access Token* de las credenciales **de prueba** de tu cuenta
   de Mercado Pago Developers. Empieza con `APP_USR-`, igual que el de producción: en Checkout Pro es así. Nunca las
   credenciales reales.
2. Mercado Pago no acepta `localhost` como dirección de vuelta, así que el sitio se abre por un túnel de ngrok:

   ```bash
   ngrok http 8000
   ```

   y se copia la dirección `https://…` que muestra a `APP_URL`, en `.env`. Cómo instalar ngrok y usar el túnel:
   [paso 8 de la guía de instalación](docs/instalacion-windows.md#8-ngrok-para-probar-el-pago-y-para-la-presentación),
   que también tiene la lista para la presentación.
3. Compilar los estilos una vez con `npm run build`, en lugar de dejar `npm run dev`: el servidor de Vite escucha en
   `localhost` y desde la dirección del túnel no se alcanza.
4. Levantar `php artisan serve`, `php artisan queue:work` y `php artisan schedule:work` como siempre, y entrar
   **siempre por la dirección del túnel**, no por `127.0.0.1:8000`: la reserva en curso queda en la sesión, y la sesión
   es de una sola dirección.

Para pagar se usa el comprador de prueba de la cuenta y las tarjetas de prueba de Mercado Pago: con el titular `APRO`
el pago se aprueba; con `FUND` u `OTHE`, se rechaza. Mercado Pago cobra en pesos el equivalente de los dólares, con su
cotización del momento.

Si al pagar aparece «No pudimos conectar con Mercado Pago» y el registro habla de un certificado, ver
[Problemas conocidos](docs/instalacion-windows.md#problemas-conocidos) en la guía de instalación.

## Cómo trabajamos

Las reglas están en [CONTRIBUTING.md](CONTRIBUTING.md). En corto: `main` siempre funciona, cada caso de uso se hace
en su rama, entra por *pull request* revisado por otro integrante, y los viernes se etiqueta una versión estable y se
respalda la base.

## Qué hay en el repositorio

Además de las carpetas de Laravel (`app`, `config`, `database`, `resources`, `routes`, etc.):

| Carpeta o archivo | Contenido |
|---|---|
| `CONTRIBUTING.md` | Forma de trabajo: ramas, commits, *pull requests*, migraciones, versiones estables y respaldos |
| `docs/instalacion-windows.md` | Instalación de PHP, Composer, Node y PostgreSQL en Windows, y problemas conocidos |
| `docs/reparto-casos-de-uso.md` | Quién hace cada caso de uso y en qué orden |
| `docs/esquema-base-de-datos.md` | Las 15 tablas del Documento de Normalización, como referencia para las migraciones |
| `docs/convenciones-de-codigo.md` | Nombres, dónde va cada cosa y formato del código |
| `scripts/respaldar-bd.sh` | Respaldo de la base de datos local en `respaldos/` |
| `scripts/instalar-laravel.sh` | Con lo que se instaló Laravel, Livewire y Filament el 03/10. Ya se usó: queda como registro |
| `.github/pull_request_template.md` | Lista de control que aparece al abrir un *pull request* |

La documentación del proyecto (ERS, modelo de dominio y casos de uso, arquitectura, normalización, diagramas) está en
el Drive del grupo, no en este repositorio.
