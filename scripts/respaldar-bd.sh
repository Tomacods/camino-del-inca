#!/usr/bin/env bash
#
# Guarda una copia de la base de datos local en la carpeta respaldos/.
# Los respaldos NO se suben al repositorio (están en .gitignore): el del
# viernes se copia además al Drive del grupo.
#
# Uso, desde la raíz del repositorio (en Windows, desde Git Bash):
#
#     bash scripts/respaldar-bd.sh              -> respaldos/camino_del_inca_2026-10-09_183000.sql
#     bash scripts/respaldar-bd.sh v0.2         -> respaldos/camino_del_inca_2026-10-09_183000_v0.2.sql
#
# Para volver a un respaldo:
#
#     psql -U postgres -d camino_del_inca -f respaldos/<archivo>.sql
#
set -euo pipefail

error() { printf '\nERROR: %s\n' "$*" >&2; exit 1; }

cd "$(dirname "$0")/.."

[ -f .env ] || error "No hay archivo .env en la raíz del proyecto."

# Lee una clave del .env (sin comillas ni saltos de línea de Windows).
leer() {
    local valor
    valor="$(grep -E "^$1=" .env | tail -n 1 | cut -d= -f2- | tr -d '\r' || true)"
    valor="${valor%\"}"
    valor="${valor#\"}"
    printf '%s' "$valor"
}

command -v pg_dump >/dev/null 2>&1 \
    || error "No se encuentra pg_dump. En Windows hay que agregar al PATH la carpeta bin de PostgreSQL
       (por ejemplo C:\\Program Files\\PostgreSQL\\17\\bin) y volver a abrir la terminal."

[ "$(leer DB_CONNECTION)" = "pgsql" ] || error "El .env no está configurado para PostgreSQL (DB_CONNECTION=pgsql)."

servidor="$(leer DB_HOST)"
puerto="$(leer DB_PORT)"
base="$(leer DB_DATABASE)"
usuario="$(leer DB_USERNAME)"
clave="$(leer DB_PASSWORD)"

[ -n "$base" ] || error "Falta DB_DATABASE en el .env."

etiqueta="${1:-}"
etiqueta="${etiqueta//[^A-Za-z0-9._-]/-}"

mkdir -p respaldos
archivo="respaldos/${base}_$(date +%Y-%m-%d_%H%M%S)${etiqueta:+_$etiqueta}.sql"

# Se escribe en un archivo aparte y se renombra al final: si pg_dump falla,
# no queda un respaldo a medias ni se pisa uno anterior.
parcial="$archivo.parcial"

if ! PGPASSWORD="$clave" pg_dump \
        --host="${servidor:-127.0.0.1}" --port="${puerto:-5432}" --username="${usuario:-postgres}" \
        --dbname="$base" --no-owner --no-privileges --clean --if-exists --file="$parcial"; then
    rm -f "$parcial"
    error "pg_dump no pudo hacer el respaldo. Revisá que PostgreSQL esté corriendo y los datos DB_* del .env."
fi

mv "$parcial" "$archivo"

echo "Respaldo guardado en $archivo"
