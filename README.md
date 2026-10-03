# Camino del Inca

Sistema web de reservas de excursiones al Camino del Inca: el cliente consulta los paquetes, reserva, paga la seña y el
saldo y valora el viaje; el administrador gestiona paquetes, excursiones, permisos y reservas; el guía reporta el avance
del recorrido.

Proyecto de la cátedra **Desarrollo de Software** (UNPSJB, 2026) — **Grupo 11**: Tomás Da Silva, César Millavanque,
Mora Anabella Molina y Mariano Reyes.

## Con qué está hecho

| Parte | Tecnología |
|---|---|
| Lógica de negocio | Laravel (PHP), patrón Modelo–Vista–Controlador |
| Portal del cliente | Vistas Blade y componentes Livewire |
| Panel del administrador y del guía | Filament |
| Acceso a datos | Eloquent (Active Record) y migraciones |
| Base de datos | PostgreSQL |
| Cobro | Mercado Pago, Checkout Pro en modo de prueba |
| Correo | SMTP; Mailtrap durante las pruebas |

## Requisitos

- **PHP 8.2 o superior**, con estas extensiones habilitadas en `php.ini`: `pdo_pgsql`, `pgsql`, `intl`, `zip`,
  `mbstring`, `fileinfo`, `curl`, `openssl`. En Windows suelen venir comentadas: se les saca el `;` de adelante.
- **Composer 2**
- **Node.js 22** (LTS) con npm
- **PostgreSQL** y pgAdmin
- **Git**. En Windows, los comandos de este repositorio se corren desde **Git Bash**.

Para comprobarlo: `php -v`, `php -m`, `composer -V`, `node -v`, `psql --version`.

## Puesta en marcha inicial (una sola vez, una sola persona)

> Esta sección se borra cuando Laravel ya esté subido al repositorio.

El repositorio arranca sólo con las reglas de trabajo y los scripts. Laravel, Livewire y Filament se instalan con:

```bash
bash scripts/instalar-laravel.sh
```

El script descarga el proyecto base de Laravel, lo ubica en la raíz sin pisar los archivos del grupo, deja el `.env`
apuntando a PostgreSQL e instala Filament. Al terminar muestra los pasos que quedan a mano (crear la base, migrar,
crear el usuario del panel y subir todo). Por defecto instala Laravel 12 y Filament 5; para otra versión:

```bash
LARAVEL="^13.0" bash scripts/instalar-laravel.sh    # Laravel 13 necesita PHP 8.3
```

## Para clonar y levantar el proyecto

```bash
git clone https://github.com/<usuario>/camino-del-inca.git
cd camino-del-inca

composer install
npm install

cp .env.example .env
php artisan key:generate
```

Después:

1. Crear en PostgreSQL una base vacía llamada `camino_del_inca` (pgAdmin, o `createdb -U postgres camino_del_inca`).
2. En `.env`, completar `DB_PASSWORD` con la contraseña de tu PostgreSQL.
3. Crear las tablas y cargar los datos de prueba:

   ```bash
   php artisan migrate --seed
   ```

4. Levantar la aplicación, en dos terminales:

   ```bash
   php artisan serve     # http://localhost:8000   (panel: http://localhost:8000/admin)
   npm run dev           # recompila los estilos mientras se trabaja
   ```

El archivo `.env` es de cada uno y no se sube. Si se agrega una variable nueva, se agrega también en `.env.example`.

## Cómo trabajamos

Las reglas están en [CONTRIBUTING.md](CONTRIBUTING.md). En corto: `main` siempre funciona, cada caso de uso se hace
en su rama, entra por *pull request* revisado por otro integrante, y los viernes se etiqueta una versión estable y se
respalda la base.

## Qué hay en el repositorio

| Carpeta o archivo | Contenido |
|---|---|
| `CONTRIBUTING.md` | Forma de trabajo: ramas, commits, *pull requests*, migraciones, versiones estables y respaldos |
| `docs/reparto-casos-de-uso.md` | Quién hace cada caso de uso y en qué orden |
| `docs/esquema-base-de-datos.md` | Las 15 tablas del Documento de Normalización, como referencia para las migraciones |
| `docs/convenciones-de-codigo.md` | Nombres, dónde va cada cosa y formato del código |
| `scripts/instalar-laravel.sh` | Instalación inicial de Laravel, Livewire y Filament (usa `scripts/configurar-env.php` para el `.env`) |
| `scripts/respaldar-bd.sh` | Respaldo de la base de datos local en `respaldos/` |
| `.github/pull_request_template.md` | Lista de control que aparece al abrir un *pull request* |

La documentación del proyecto (ERS, modelo de dominio y casos de uso, arquitectura, normalización, diagramas) está en
el Drive del grupo, no en este repositorio.
