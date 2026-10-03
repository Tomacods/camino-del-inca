#!/usr/bin/env bash
#
# Instala Laravel, Livewire y Filament dentro de este repositorio.
#
# Lo corre UNA sola persona del grupo, UNA sola vez. Después sube el resultado
# y el resto clona y sigue el README ("Para clonar y levantar el proyecto").
#
# Uso, desde la raíz del repositorio (en Windows, desde Git Bash):
#
#     bash scripts/instalar-laravel.sh
#
# Las versiones se pueden cambiar sin tocar este archivo:
#
#     LARAVEL="^13.0" bash scripts/instalar-laravel.sh
#
# Si se corta a mitad de camino (por ejemplo, sin internet) se puede volver a
# correr: los pasos que ya están hechos se saltean.
#
set -euo pipefail

LARAVEL="${LARAVEL:-^12.0}"
FILAMENT="${FILAMENT:-^5.0}"
TMP="_laravel_tmp"

paso()  { printf '\n==> %s\n' "$*"; }
aviso() { printf '    %s\n' "$*"; }
error() { printf '\nERROR: %s\n' "$*" >&2; exit 1; }

trap 'printf "\nLa instalación se cortó en el último paso que se ve arriba. Corregido el problema, se puede volver a correr el script.\n" >&2' ERR

cd "$(dirname "$0")/.."

# --------------------------------------------------------------------------
# 0. Verificaciones
# --------------------------------------------------------------------------
paso "Verificando el entorno"

[ -d .git ] || error "Hay que correrlo dentro del repositorio clonado (no se encontró .git)."

for programa in php composer node npm git; do
    command -v "$programa" >/dev/null 2>&1 \
        || error "No se encuentra '$programa'. Instalalo y volvé a correr el script (ver README, Requisitos)."
done

php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' \
    || error "Se necesita PHP 8.2 o superior. Versión instalada: $(php -r 'echo PHP_VERSION;')"

faltan=""
for extension in ctype curl dom fileinfo intl mbstring openssl pdo pdo_pgsql session tokenizer xml zip; do
    php -r "exit(extension_loaded('$extension') ? 0 : 1);" || faltan="$faltan $extension"
done
[ -z "$faltan" ] || error "Faltan extensiones de PHP:$faltan
       Se habilitan en php.ini (archivo: $(php -r 'echo php_ini_loaded_file() ?: "no encontrado";'))
       quitando el ';' de las líneas 'extension=...' y volviendo a abrir la terminal."

aviso "PHP $(php -r 'echo PHP_VERSION;') · $(composer --version 2>/dev/null | head -n 1)"
aviso "Node $(node --version) · npm $(npm --version)"
aviso "Se va a instalar Laravel $LARAVEL y Filament $FILAMENT"

# --------------------------------------------------------------------------
# 1. Proyecto base de Laravel
# --------------------------------------------------------------------------
if [ -f artisan ] && [ -f composer.json ]; then
    paso "Laravel ya está en el repositorio: se saltea la descarga"
else
    paso "Descargando el proyecto base de Laravel $LARAVEL"
    rm -rf "$TMP"
    composer create-project "laravel/laravel:$LARAVEL" "$TMP" \
        --no-install --no-scripts --no-interaction --prefer-dist

    paso "Pasando los archivos de Laravel a la raíz del repositorio"
    shopt -s dotglob nullglob
    for origen in "$TMP"/*; do
        nombre="$(basename "$origen")"
        if [ "$nombre" = ".git" ]; then
            continue
        elif [ ! -e "$nombre" ]; then
            mv "$origen" "$nombre"
        elif [ -d "$origen" ] && [ -d "$nombre" ]; then
            cp -Rn "$origen"/. "$nombre"/ 2>/dev/null || true
            aviso "$nombre/: ya existía; se agregaron los archivos de Laravel sin pisar los del grupo"
        else
            aviso "$nombre: se conserva el del repositorio"
        fi
    done
    shopt -u dotglob nullglob
    rm -rf "$TMP"
fi

# --------------------------------------------------------------------------
# 2. Dependencias de PHP
# --------------------------------------------------------------------------
paso "Instalando las dependencias de PHP (composer install)"
composer install --no-interaction

# --------------------------------------------------------------------------
# 3. Configuración: PostgreSQL, idioma, Mercado Pago y correo
# --------------------------------------------------------------------------
paso "Configurando .env.example y .env"
php scripts/configurar-env.php .env.example
[ -f .env ] || cp .env.example .env
php scripts/configurar-env.php .env

if ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate
fi

# --------------------------------------------------------------------------
# 4. Filament (panel del administrador y del guía) y Livewire
# --------------------------------------------------------------------------
if [ -d app/Providers/Filament ]; then
    paso "Filament ya está instalado: se saltea"
else
    paso "Instalando Filament $FILAMENT"
    composer require "filament/filament:$FILAMENT" -W --no-interaction
    aviso "Si pregunta por el ID del panel, dejar 'admin' (Enter)."
    php artisan filament:install --panels
fi

if ! grep -q '"livewire/livewire"' composer.json; then
    paso "Dejando Livewire como dependencia directa (portal del cliente)"
    composer require livewire/livewire --no-interaction
fi

# --------------------------------------------------------------------------
# 5. Dependencias de JavaScript
# --------------------------------------------------------------------------
paso "Instalando las dependencias de JavaScript (npm install)"
npm install

paso "Compilando los estilos una vez, para comprobar que funciona (npm run build)"
npm run build

# --------------------------------------------------------------------------
# Listo
# --------------------------------------------------------------------------
cat <<'FIN'

==> Instalación terminada. Lo que falta se hace a mano:

    1. Crear la base de datos vacía "camino_del_inca" en PostgreSQL
       (con pgAdmin, o con:  createdb -U postgres camino_del_inca)
    2. Poner tu contraseña de PostgreSQL en DB_PASSWORD, en el archivo .env
    3. php artisan migrate
    4. php artisan make:filament-user        (usuario para entrar al panel)
    5. En dos terminales:  php artisan serve      y      npm run dev
    6. Probar  http://localhost:8000  y  http://localhost:8000/admin
    7. Subirlo para que el resto lo pueda clonar:

           git add -A
           git commit -m "Base: instalación de Laravel, Livewire y Filament"
           git push

    Después, borrar del README la sección "Puesta en marcha inicial".
FIN
