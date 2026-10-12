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

### El sitio muestra *Connection refused* en el puerto 5432

Síntoma: al abrir el sitio aparece una página de error `Illuminate\Database\QueryException` con *SQLSTATE[08006] [7]
connection to server at "127.0.0.1", port 5432 failed: Connection refused*. Lo mismo responden `php artisan migrate` y
`php artisan queue:work`. Las pruebas (`php artisan test`) siguen andando, porque usan otra base.

Es que **el servicio de PostgreSQL existe pero está detenido**. Pasa sobre todo después de reiniciar la PC. No es un
error del código.

En **PowerShell como administrador**:

```powershell
Get-Service *postgres*
```

Muestra el servicio con su nombre (columna `Name`) y su estado. El nombre depende de cómo se instaló: el instalador lo
llama `postgresql-x64-` y la versión (por ejemplo `postgresql-x64-18`); si se creó a mano con la sección anterior, se
llama `postgresql`. Con ese nombre:

```powershell
Start-Service postgresql
Get-Service postgresql | Select-Object Name, Status, StartType
```

Tiene que decir `Running`. Después alcanza con recargar la página: `php artisan serve` no hace falta reiniciarlo, pero
`php artisan queue:work` sí, si estaba corriendo, porque se corta cuando pierde la conexión con la base.

No hace falta que PostgreSQL arranque con Windows: alcanza con arrancarlo así antes de trabajar. Si se prefiere que
arranque solo, se fija qué dice `StartType`:

- **`Manual`:** se cambia con `Set-Service postgresql -StartupType Automatic`.
- **`Automatic`:** intentó arrancar con la PC y falló. El motivo queda en el Visor de eventos (menú Inicio → «Visor de
  eventos» → Registros de Windows → Aplicación), en las entradas con origen PostgreSQL.

Si `Get-Service *postgres*` no muestra nada, es el caso de la sección anterior.

### Mercado Pago: *unable to get local issuer certificate*

Sólo le pasa a quien prueba el pago con Mercado Pago. Síntoma: «Pagar con Mercado Pago» muestra «No pudimos conectar con
Mercado Pago», y en `storage/logs/laravel.log` queda *SSL certificate OpenSSL verify result: unable to get local issuer
certificate (20)*.

Es que el PHP para Windows no trae los certificados con los que se validan las conexiones `https`, así que no puede
comprobar que del otro lado está Mercado Pago. Composer no tiene el problema porque trae los suyos.

En PowerShell, bajar los certificados:

```powershell
mkdir C:\php\extras\ssl -Force
curl.exe -L -o C:\php\extras\ssl\cacert.pem https://curl.se/ca/cacert.pem
```

`php --ini` dice qué `php.ini` se usa (normalmente `C:\php\php.ini`). Ahí, buscar estas dos líneas, sacarles el `;` del
principio y dejarlas así:

```ini
curl.cainfo = "C:\php\extras\ssl\cacert.pem"
openssl.cafile = "C:\php\extras\ssl\cacert.pem"
```

Cuidado con no dejar el nombre dos veces en la misma línea (`openssl.cafile=openssl.cafile = …`): PHP avisa *syntax
error, unexpected '='* y no toma el valor. Para comprobarlo:

```bash
php -r "echo ini_get('curl.cainfo'), PHP_EOL, ini_get('openssl.cafile'), PHP_EOL;"
```

Tiene que imprimir la ruta dos veces. Después, reiniciar `php artisan serve` y `php artisan queue:work`, que leen el
`php.ini` al arrancar. No usar `MercadoPagoConfig::setRuntimeEnviroment(LOCAL)` del SDK para esquivarlo: desactiva la
verificación del certificado.

### Aviso "Parte de esta aplicación se ha bloqueado" al abrir pgAdmin

También es el Control inteligente de aplicaciones, que bloquea un archivo secundario de pgAdmin. Normalmente pgAdmin
abre igual. Si no abre, la base se crea desde la terminal (paso 7).

### En Git Bash no funcionan Ctrl+C y Ctrl+V para copiar y pegar

Se pega con `Shift + Insert` (o clic derecho → Paste) y se copia seleccionando con el mouse. `Ctrl + C` corta el
comando que está corriendo.
