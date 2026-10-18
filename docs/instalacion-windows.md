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

## 8. ngrok: para probar el pago y para la presentación

Lo necesita quien pruebe el pago de la reserva (CU-15) y **la PC con la que se muestre el sistema en la presentación
final**. Mercado Pago no acepta `localhost` como dirección de vuelta, así que el sitio se abre desde afuera con un túnel
de ngrok: una dirección pública `https` que le pasa los pedidos a `localhost:8000`. Lo demás de la prueba del pago
(credenciales y comprador de prueba) está en el [README](../README.md#probar-el-pago-con-mercado-pago).

Una sola vez:

1. Crear una cuenta gratis en [ngrok.com](https://ngrok.com).
2. Instalarlo desde PowerShell, o buscando «ngrok» en la Microsoft Store:

   ```powershell
   winget install ngrok -s msstore
   ```

   Conviene la versión de la Microsoft Store: el `.exe` bajado suelto a veces lo bloquea Windows Defender.
3. En una terminal nueva, comprobar que responde con `ngrok help`.
4. Registrar el token de la cuenta, que se copia de
   [dashboard.ngrok.com/get-started/your-authtoken](https://dashboard.ngrok.com/get-started/your-authtoken):

   ```bash
   ngrok config add-authtoken <token>
   ```

Cada vez que se prueba el pago, en una terminal aparte, además de `php artisan serve`, `php artisan queue:work` y
`php artisan schedule:work`:

```bash
ngrok http 8000
```

La línea *Forwarding* muestra la dirección pública, del estilo `https://tres-palabras.ngrok-free.dev` (a algunas cuentas
les toca `.ngrok-free.app`). En el plan gratis es siempre la misma, así que se configura una sola vez: va en `APP_URL`
del `.env`, sin barra al final, y después `php artisan config:clear`. El túnel funciona mientras esa terminal esté
abierta y se corta con `Ctrl+C`. En <http://127.0.0.1:4040> se ven los pedidos que pasan por él.

Para usarlo:

- **Entrar siempre por la dirección del túnel**, no por `127.0.0.1:8000`: la reserva en curso queda en la sesión, que es
  de una sola dirección, y Mercado Pago devuelve al cliente a la del túnel.
- **La primera visita** desde un navegador muestra una página de aviso de ngrok: se pasa con *Visit Site*. En una ventana
  de incógnito aparece cada vez que se abre una nueva.
- **Compilar con `npm run build`** y no dejar `npm run dev` corriendo: el servidor de Vite escucha en `localhost` y desde
  el túnel no se alcanza, así que el sitio se vería sin estilos.

El plan gratis alcanza de sobra para probar: 1 GB y 20.000 pedidos por mes.

### Para la presentación

La presentación se hace con la PC que tenga ngrok y las credenciales de prueba de Mercado Pago en su `.env`: la
dirección del túnel es de una cuenta de ngrok, y las credenciales, de una cuenta de Mercado Pago. Antes de empezar:

1. Internet andando: sin conexión no hay túnel ni Mercado Pago.
2. PostgreSQL arrancado (`Start-Service postgresql`, ver «Problemas conocidos»).
3. `npm run build`, y en cuatro terminales `php artisan serve`, `php artisan queue:work`, `php artisan schedule:work` y
   `ngrok http 8000`.
4. En el `.env`, `APP_URL` con la dirección del túnel y `RESERVA_MINUTOS_RETENCION` en 5 o sin la línea, que es el
   plazo del caso de uso.
5. Una ventana de incógnito abierta en la dirección del túnel, con el aviso de ngrok ya pasado.
6. A mano, el usuario y la contraseña del comprador de prueba y una tarjeta de prueba (titular `APRO` para aprobar,
   `FUND` para mostrar un rechazo). Si se entra al checkout sin iniciar sesión, Mercado Pago pide un correo: va el del
   comprador de prueba, nunca uno real.

Conviene ensayarlo una vez con los 5 minutos reales de retención, y tener capturas del pago por si falla la conexión.

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
