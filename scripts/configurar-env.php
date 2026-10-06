<?php

/*
 * Ajusta un archivo .env (o .env.example) de Laravel a lo que usa el grupo:
 * PostgreSQL, idioma español y los datos de Mercado Pago y del correo.
 *
 * Lo llama scripts/instalar-laravel.sh. Uso: php scripts/configurar-env.php <archivo>
 *
 * Se puede correr más de una vez: no pisa lo que cada uno ya haya completado
 * (por ejemplo, su contraseña de PostgreSQL).
 */

$archivo = $argv[1] ?? null;

if ($archivo === null || ! is_file($archivo)) {
    fwrite(STDERR, 'No se encuentra el archivo: '.($archivo ?? '(falta el nombre)').PHP_EOL);
    exit(1);
}

$texto = file_get_contents($archivo);
$finDeLinea = str_contains($texto, "\r\n") ? "\r\n" : "\n";
$lineas = preg_split('/\r\n|\n/', rtrim($texto, "\r\n"));

/** Devuelve [índice de la línea activa, índice de la línea comentada] de una clave. */
function buscar(array $lineas, string $clave): array
{
    $activa = null;
    $comentada = null;

    foreach ($lineas as $i => $linea) {
        if ($activa === null && preg_match('/^'.preg_quote($clave, '/').'=/', $linea)) {
            $activa = $i;
        } elseif ($comentada === null && preg_match('/^#\s*'.preg_quote($clave, '/').'=/', $linea)) {
            $comentada = $i;
        }
    }

    return [$activa, $comentada];
}

/** Fija el valor siempre, exista o no la clave. */
function forzar(array &$lineas, string $clave, string $valor): void
{
    [$activa, $comentada] = buscar($lineas, $clave);
    $indice = $activa ?? $comentada;

    if ($indice !== null) {
        $lineas[$indice] = "$clave=$valor";
    } else {
        $lineas[] = "$clave=$valor";
    }
}

/** Fija el valor sólo si la clave no está o está comentada. */
function completar(array &$lineas, string $clave, string $valor): void
{
    [$activa, $comentada] = buscar($lineas, $clave);

    if ($activa !== null) {
        return;
    }

    if ($comentada !== null) {
        $lineas[$comentada] = "$clave=$valor";
    } else {
        $lineas[] = "$clave=$valor";
    }
}

/** Cambia el valor sólo si todavía tiene el que trae Laravel. */
function reemplazarPorDefecto(array &$lineas, string $clave, string $valorDeLaravel, string $valor): void
{
    [$activa] = buscar($lineas, $clave);

    if ($activa !== null && rtrim($lineas[$activa]) === "$clave=$valorDeLaravel") {
        $lineas[$activa] = "$clave=$valor";
    }
}

// Aplicación
reemplazarPorDefecto($lineas, 'APP_NAME', 'Laravel', '"Camino del Inca"');
reemplazarPorDefecto($lineas, 'APP_LOCALE', 'en', 'es');
reemplazarPorDefecto($lineas, 'APP_FAKER_LOCALE', 'en_US', 'es_AR');

// Base de datos
forzar($lineas, 'DB_CONNECTION', 'pgsql');
completar($lineas, 'DB_HOST', '127.0.0.1');
completar($lineas, 'DB_PORT', '5432');
completar($lineas, 'DB_DATABASE', 'camino_del_inca');
completar($lineas, 'DB_USERNAME', 'postgres');
completar($lineas, 'DB_PASSWORD', '');

// Mercado Pago y nota sobre el correo
[$mercadoPago] = buscar($lineas, 'MERCADOPAGO_ACCESS_TOKEN');

if ($mercadoPago === null) {
    array_push(
        $lineas,
        '',
        '# Mercado Pago (Checkout Pro). Van las credenciales de PRUEBA de cada uno, nunca las reales.',
        'MERCADOPAGO_PUBLIC_KEY=',
        'MERCADOPAGO_ACCESS_TOKEN=',
        '',
        '# Correo: con MAIL_MAILER=log los correos se escriben en storage/logs/laravel.log.',
        '# Para verlos en Mailtrap: MAIL_MAILER=smtp, MAIL_HOST=sandbox.smtp.mailtrap.io, MAIL_PORT=2525',
        '# y en MAIL_USERNAME y MAIL_PASSWORD los datos de la bandeja de Mailtrap de cada uno.',
    );
}

file_put_contents($archivo, implode($finDeLinea, $lineas).$finDeLinea);

echo "    $archivo: listo".PHP_EOL;
