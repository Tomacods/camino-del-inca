# Instalación en Windows

Pasos para dejar una PC con Windows lista para trabajar en el proyecto. Son los que se siguieron el 03/10/2026 en la
primera instalación, con los problemas que aparecieron y cómo se resolvieron. Todo lo que dice "en Git Bash" se corre
en la terminal que instala Git para Windows.

Conviene seguir el orden: el paso 1 evita los dos problemas que más tiempo llevaron.

## 1. Desactivar el Control inteligente de aplicaciones

Windows 11 trae una protección llamada **Control inteligente de aplicaciones** que bloquea los programas que no están
firmados digitalmente. El PHP oficial para Windows no está firmado, así que con esa protección activada **PHP deja de
arrancar**: los comandos `php` no muestran nada y terminan con código 127. No se puede hacer una excepción para un
programa solo.

Para desactivarla: **Seguridad de Windows** → **Control de aplicaciones y navegador** → **Configuración de Control
inteligente de aplicaciones** → **Desactivado**. El antivirus de Windows y SmartScreen siguen funcionando.

Con Windows actualizado se puede volver a activar más adelante. Antes de confirmar, leer el aviso que muestra esa
pantalla: si dice que no se puede reactivar sin reinstalar Windows, falta una actualización del sistema.

Si en tu PC ya figura como desactivado, no hay que hacer nada.

## 2. Node.js

Bajar el instalador **LTS** para Windows desde [nodejs.org](https://nodejs.org) e instalarlo con las opciones por
defecto.

## 3. PostgreSQL

Bajar el instalador desde [postgresql.org/download/windows](https://www.postgresql.org/download/windows/).

- Pide una contraseña para el usuario `postgres`: anotarla, va en el `.env`.
- Dejar el puerto `5432`.
- Al final, destildar "Stack Builder".

Para comprobar que quedó funcionando, en Git Bash:

```bash
powershell -Command "Get-Service *postgres*"
```

Tiene que aparecer un servicio en estado `Running`. Si no aparece ninguno, ver
[El instalador de PostgreSQL no creó el servicio](#el-instalador-de-postgresql-no-creó-el-servicio).

## 4. PHP

1. En [windows.php.net/download](https://windows.php.net/download/), bajar el **Zip** de la versión **x64 Non Thread
   Safe** (8.4 u 8.5).
2. Descomprimirlo en `C:\php`, de modo que quede `C:\php\php.exe` (no una carpeta dentro de otra).
3. En Git Bash, crear la configuración y habilitar las extensiones:

   ```bash
   cd /c/php
   cp php.ini-development php.ini
   sed -i -E 's/^;(extension_dir = "ext")/\1/; s/^;(extension=(curl|fileinfo|intl|mbstring|openssl|pdo_pgsql|pdo_sqlite|pgsql|sqlite3|zip)\b)/\1/' php.ini
   ./php -m | grep -E "intl|pgsql|zip|mbstring"
   ```

   El último comando tiene que listar `intl`, `mbstring`, `pdo_pgsql`, `pgsql` y `zip`. Si aparece un error de
   `VCRUNTIME140.dll`, falta instalar el "Visual C++ Redistributable 2015-2022 x64" de Microsoft.

## 5. Composer

Bajar y ejecutar [Composer-Setup.exe](https://getcomposer.org/Composer-Setup.exe). Cuando pregunte qué PHP usar, elegir
`C:\php\php.exe` y aceptar que lo agregue al PATH.

## 6. Comprobar

Cerrar **todas** las ventanas de Git Bash, abrir una nueva y correr:

```bash
php -v
composer -V
node -v
```

Si los tres responden con su versión, seguir con "Para clonar y levantar el proyecto" del [README](../README.md).

## 7. Opcional: herramientas de PostgreSQL en la terminal

Hace falta para `createdb`, `psql` y para el script de respaldo (`scripts/respaldar-bd.sh`). Menú Inicio → escribir
"variables de entorno" → **Editar las variables de entorno de esta cuenta** → **Path** → **Editar** → **Nuevo** →
la carpeta `bin` de PostgreSQL (por ejemplo `C:\Program Files\PostgreSQL\18\bin`). Después, abrir una terminal nueva.

Sin esto, la base se puede crear igual desde pgAdmin, o llamando al programa con la ruta completa:

```bash
"/c/Program Files/PostgreSQL/18/bin/createdb" -U postgres camino_del_inca
```

## Problemas conocidos

### `php` no muestra nada y termina con código 127

Es el Control inteligente de aplicaciones (paso 1). Puede pasar aunque PHP haya funcionado un rato antes. Para
confirmarlo, en Git Bash:

```bash
php -v; echo "codigo=$?"
powershell -Command "Get-WinEvent -LogName 'Microsoft-Windows-CodeIntegrity/Operational' -MaxEvents 8 | Format-List TimeCreated, Message"
```

Si el registro nombra a `php8.dll`, es eso.

### El instalador de PostgreSQL no creó el servicio

Síntoma: `createdb` o `php artisan migrate` responden *Connection refused* en el puerto 5432, no existe ningún servicio
de PostgreSQL y la carpeta `data` de la instalación está vacía. Se completa a mano, sin reinstalar.

Abrir **PowerShell como administrador** (menú Inicio → PowerShell → clic derecho → Ejecutar como administrador) y
correr, cambiando la primera línea por la carpeta donde quedó instalado:

```powershell
$pg = "C:\Program Files\PostgreSQL\18"

& "$pg\bin\initdb.exe" -D "$pg\data" -U postgres -W -A scram-sha-256 -E UTF8 --locale=C --locale-provider=icu --icu-locale=es-AR
& "$pg\bin\pg_ctl.exe" register -N postgresql -D "$pg\data"
Start-Service postgresql
Get-Service postgresql
```

El primer comando pide dos veces la contraseña nueva del usuario `postgres`. El último tiene que mostrar `Running`; el
servicio queda configurado para arrancar solo con la PC.

### Aviso "Parte de esta aplicación se ha bloqueado" al abrir pgAdmin

También es el Control inteligente de aplicaciones, que bloquea un archivo secundario de pgAdmin. Normalmente pgAdmin
abre igual. Si no abre, la base se crea desde la terminal (paso 7).

### En Git Bash no funcionan Ctrl+C y Ctrl+V para copiar y pegar

Se pega con `Shift + Insert` (o clic derecho → Paste) y se copia seleccionando con el mouse. `Ctrl + C` corta el
comando que está corriendo.
