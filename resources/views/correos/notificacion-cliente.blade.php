{{-- Correo de Notificar Cliente (CU-13). Una sola vista para los ocho tipos: lo común (los datos de la reserva y el botón
     a Mi reserva) va para todos, y en el medio, el bloque del tipo. Todo llega calculado desde Reserva::notificar(): la
     vista sólo lo muestra. HTML simple, con los estilos en cada etiqueta, porque los programas de correo no leen hojas
     de estilo. --}}
@use('App\Enums\EstadoSaldo')
@use('App\Enums\TipoNotificacion')

@php
    $dolares = fn (float $monto) => 'USD '.number_format($monto, 2, ',', '.');
    $reserva = $datos['reserva'];
    $excursion = $datos['excursion'];
    $celdaTitulo = 'padding:6px 12px 6px 0;color:#6b6259;vertical-align:top;white-space:nowrap';
    $celdaDato = 'padding:6px 0;font-weight:bold;vertical-align:top';
@endphp

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $tipo->value }} · Reserva {{ $reserva['numero_reserva'] }}</title>
</head>
<body style="margin:0;padding:0;background:#f4efe7;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.5;color:#2d2620">
<div style="max-width:600px;margin:0 auto;padding:24px 16px">
    <p style="margin:0 0 16px;font-size:18px;font-weight:bold;color:#8a4b2a">{{ config('app.name') }}</p>

    <div style="background:#ffffff;border-radius:8px;padding:24px">
        <p style="margin:0 0 16px">Hola:</p>

        @switch($tipo)
            @case(TipoNotificacion::PrimeraCompra)
                <p style="margin:0 0 8px">Tu reserva quedó registrada. Guardá este número: con él y tu correo podés consultarla cuando quieras.</p>
                <p style="margin:0 0 24px;font-size:28px;font-weight:bold;letter-spacing:1px">{{ $reserva['numero_reserva'] }}</p>

                <h2 style="margin:0 0 8px;font-size:18px">Comprobante</h2>
                <table style="border-collapse:collapse;margin:0 0 24px">
                    @if ($datos['comprobante'] !== null)
                        <tr><td style="{{ $celdaTitulo }}">Comprobante n.º</td><td style="{{ $celdaDato }}">{{ $datos['comprobante']['numero_comprobante'] }}</td></tr>
                        <tr><td style="{{ $celdaTitulo }}">Emitido</td><td style="{{ $celdaDato }}">{{ $datos['comprobante']['fecha_emision']->format('d/m/Y H:i') }}</td></tr>
                    @endif
                    <tr><td style="{{ $celdaTitulo }}">Excursión</td><td style="{{ $celdaDato }}">{{ $excursion['paquete'] }}, salida el {{ $excursion['fecha_salida']->format('d/m/Y') }}</td></tr>
                    <tr><td style="{{ $celdaTitulo }}">Recorrido</td><td style="{{ $celdaDato }}">{{ $datos['paquete']['recorrido']['nombre_recorrido'] }} ({{ $datos['paquete']['recorrido']['duracion_dias'] }} días)</td></tr>
                    <tr>
                        <td style="{{ $celdaTitulo }}">Servicios incluidos</td>
                        <td style="{{ $celdaDato }}">
                            @foreach ($datos['paquete']['servicios'] as $servicio)
                                {{ $servicio['tipo']->value }}: {{ $servicio['nombre'] }}<br>
                            @endforeach
                        </td>
                    </tr>
                    <tr><td style="{{ $celdaTitulo }}">Noches extra en Cusco</td><td style="{{ $celdaDato }}">{{ $reserva['noches_extra_antes'] }} antes y {{ $reserva['noches_extra_despues'] }} después del recorrido</td></tr>
                    <tr><td style="{{ $celdaTitulo }}">Precio final</td><td style="{{ $celdaDato }}">{{ $dolares($datos['monto_total']) }}</td></tr>
                    <tr><td style="{{ $celdaTitulo }}">Abonado ({{ $datos['ultimo_pago']['tipo_pago']->value }})</td><td style="{{ $celdaDato }}">{{ $dolares($datos['ultimo_pago']['monto']) }}, con {{ $datos['ultimo_pago']['medio_pago'] }}</td></tr>
                </table>
                @break

            @case(TipoNotificacion::PagoSaldo)
                <p style="margin:0 0 24px">Recibimos el pago del saldo: tu reserva <strong>{{ $reserva['numero_reserva'] }}</strong> está pagada por completo.</p>

                <h2 style="margin:0 0 8px;font-size:18px">Comprobante del pago</h2>
                <table style="border-collapse:collapse;margin:0 0 24px">
                    @if ($datos['comprobante'] !== null)
                        <tr><td style="{{ $celdaTitulo }}">Comprobante n.º</td><td style="{{ $celdaDato }}">{{ $datos['comprobante']['numero_comprobante'] }}</td></tr>
                        <tr><td style="{{ $celdaTitulo }}">Emitido</td><td style="{{ $celdaDato }}">{{ $datos['comprobante']['fecha_emision']->format('d/m/Y H:i') }}</td></tr>
                    @endif
                    <tr><td style="{{ $celdaTitulo }}">Monto abonado</td><td style="{{ $celdaDato }}">{{ $dolares($datos['ultimo_pago']['monto']) }}, con {{ $datos['ultimo_pago']['medio_pago'] }}</td></tr>
                    <tr><td style="{{ $celdaTitulo }}">Saldo</td><td style="{{ $celdaDato }}">{{ $reserva['estado_saldo']->value }}</td></tr>
                </table>
                @break

            @case(TipoNotificacion::CancelacionReserva)
                <p style="margin:0 0 16px">Tu reserva <strong>{{ $reserva['numero_reserva'] }}</strong> fue cancelada el {{ $datos['fecha_envio']->format('d/m/Y') }}.</p>

                @if ($datos['devolucion'] !== null)
                    <p style="margin:0 0 24px">Como cancelaste con más de {{ $datos['dias_anticipacion_reembolso'] }} días de anticipación, te corresponde el {{ $datos['porcentaje_reembolso'] }} % de lo abonado: un {{ mb_strtolower($datos['devolucion']['motivo']->value) }} de <strong>{{ $dolares($datos['devolucion']['monto']) }}</strong>, registrado el {{ $datos['devolucion']['fecha']->format('d/m/Y') }}.</p>
                @else
                    <p style="margin:0 0 24px">Como faltaban {{ $datos['dias_anticipacion_reembolso'] }} días o menos para la salida, la cancelación no tiene devolución.</p>
                @endif
                @break

            @case(TipoNotificacion::PermisosObtenidos)
                <p style="margin:0 0 16px">Todos los integrantes obtuvieron el permiso de ingreso al Camino Inca: tu reserva pasó a <strong>«{{ $reserva['estado']->value }}»</strong>.</p>

                @if ($datos['saldo_por_pagar'] !== null)
                    <p style="margin:0 0 24px">Te queda un saldo de <strong>{{ $dolares($datos['saldo_por_pagar']['monto']) }}</strong>, que podés pagar desde Mi reserva hasta el {{ $datos['saldo_por_pagar']['fecha_limite']->format('d/m/Y') }}.</p>
                @else
                    <p style="margin:0 0 24px">Tu reserva ya está pagada por completo.</p>
                @endif
                @break

            @case(TipoNotificacion::PermisosRechazados)
                <p style="margin:0 0 8px">Estos integrantes no obtuvieron el permiso de ingreso al Camino Inca:</p>
                <ul style="margin:0 0 16px;padding-left:20px">
                    @foreach ($datos['sin_permiso'] as $integrante)
                        <li>{{ $integrante }}</li>
                    @endforeach
                </ul>
                <p style="margin:0 0 8px">Por eso tu reserva completa pasó a <strong>«{{ $reserva['estado']->value }}»</strong>. Hasta el <strong>{{ $reserva['fecha_limite_confirmacion']->format('d/m/Y') }} a las {{ $reserva['fecha_limite_confirmacion']->format('H:i') }}</strong> podés elegir, desde Mi reserva, una de estas opciones:</p>
                <ul style="margin:0 0 16px;padding-left:20px">
                    <li>Pedir el reintegro de todo lo abonado: {{ $dolares($datos['monto_reintegro']) }}.</li>
                    <li>Reprogramar la reserva para otra salida.</li>
                </ul>
                <p style="margin:0 0 24px">Si al vencer esa fecha no elegiste ninguna, la reserva se cancela y te reintegramos todo lo abonado.</p>
                @break

            @case(TipoNotificacion::CancelacionFaltaPago)
                <p style="margin:0 0 16px">Tu reserva <strong>{{ $reserva['numero_reserva'] }}</strong> fue cancelada el {{ $datos['fecha_envio']->format('d/m/Y') }} porque el saldo no se pagó antes de la fecha límite, el {{ $reserva['fecha_limite_saldo']->format('d/m/Y') }}.</p>
                <p style="margin:0 0 24px">La seña abonada ({{ $dolares($datos['monto_abonado']) }}) no es reembolsable.</p>
                @break

            @case(TipoNotificacion::EtapaFinal)
                <p style="margin:0 0 16px">Tu excursión terminó y tu reserva pasó a <strong>«{{ $reserva['estado']->value }}»</strong>. ¡Gracias por recorrer el Camino Inca con nosotros!</p>
                <p style="margin:0 0 24px">Ya podés valorar los servicios desde Mi reserva.</p>
                @break

            @case(TipoNotificacion::CancelacionFaltaPermisos)
                <p style="margin:0 0 16px">
                    Tu reserva <strong>{{ $reserva['numero_reserva'] }}</strong> fue cancelada el {{ $datos['fecha_envio']->format('d/m/Y') }} por falta de permisos,
                    @if ($datos['cancelada_por_vencimiento'])
                        porque el {{ $reserva['fecha_limite_confirmacion']->format('d/m/Y') }} venció el plazo de confirmación sin que la reserva quedara confirmada.
                    @else
                        porque pediste el reintegro.
                    @endif
                </p>
                @if ($datos['devolucion'] !== null)
                    <p style="margin:0 0 24px">Te reintegramos todo lo abonado: un {{ mb_strtolower($datos['devolucion']['motivo']->value) }} de <strong>{{ $dolares($datos['devolucion']['monto']) }}</strong>, registrado el {{ $datos['devolucion']['fecha']->format('d/m/Y') }}.</p>
                @endif
                @break
        @endswitch

        <h2 style="margin:0 0 8px;font-size:18px">Tu reserva</h2>
        <table style="border-collapse:collapse;margin:0 0 24px">
            <tr><td style="{{ $celdaTitulo }}">Reserva n.º</td><td style="{{ $celdaDato }}">{{ $reserva['numero_reserva'] }}</td></tr>
            <tr><td style="{{ $celdaTitulo }}">Estado</td><td style="{{ $celdaDato }}">{{ $reserva['estado']->value }}</td></tr>
            <tr><td style="{{ $celdaTitulo }}">Paquete</td><td style="{{ $celdaDato }}">{{ $excursion['paquete'] }}</td></tr>
            <tr><td style="{{ $celdaTitulo }}">Salida</td><td style="{{ $celdaDato }}">{{ $excursion['fecha_salida']->format('d/m/Y') }}</td></tr>
            <tr>
                <td style="{{ $celdaTitulo }}">Integrantes</td>
                <td style="{{ $celdaDato }}">
                    @foreach ($datos['excursionistas'] as $integrante)
                        {{ $integrante['nombre'] }} {{ $integrante['apellido'] }} · permiso {{ mb_strtolower($integrante['estado_permiso']->value) }}<br>
                    @endforeach
                </td>
            </tr>
            <tr><td style="{{ $celdaTitulo }}">Noches extra</td><td style="{{ $celdaDato }}">{{ $datos['noches_extra'] }}</td></tr>
            <tr><td style="{{ $celdaTitulo }}">Monto total</td><td style="{{ $celdaDato }}">{{ $dolares($datos['monto_total']) }}</td></tr>
            <tr>
                <td style="{{ $celdaTitulo }}">Pagos</td>
                <td style="{{ $celdaDato }}">
                    @foreach ($datos['pagos'] as $pago)
                        {{ $pago['tipo_pago']->value }} · {{ $pago['fecha']->format('d/m/Y') }} · {{ $dolares($pago['monto']) }} · {{ $pago['medio_pago'] }}<br>
                    @endforeach
                </td>
            </tr>
            <tr><td style="{{ $celdaTitulo }}">Abonado</td><td style="{{ $celdaDato }}">{{ $dolares($datos['monto_abonado']) }}</td></tr>
            @if ($datos['saldo_por_pagar'] !== null)
                <tr><td style="{{ $celdaTitulo }}">Saldo pendiente</td><td style="{{ $celdaDato }}">{{ $dolares($datos['saldo_por_pagar']['monto']) }}, hasta el {{ $datos['saldo_por_pagar']['fecha_limite']->format('d/m/Y') }}</td></tr>
            @elseif ($reserva['estado_saldo'] === EstadoSaldo::Abonado)
                <tr><td style="{{ $celdaTitulo }}">Saldo</td><td style="{{ $celdaDato }}">{{ $reserva['estado_saldo']->value }}</td></tr>
            @endif
            @if ($datos['fecha_limite_permisos'] !== null)
                <tr><td style="{{ $celdaTitulo }}">Confirmación de permisos</td><td style="{{ $celdaDato }}">hasta el {{ $datos['fecha_limite_permisos']->format('d/m/Y') }}</td></tr>
            @endif
        </table>

        <p style="margin:0">
            <a href="{{ url('/mi-reserva') }}" style="display:inline-block;background:#8a4b2a;color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 24px;border-radius:6px">Ver mi reserva</a>
        </p>
    </div>

    <p style="margin:16px 0 0;font-size:13px;color:#6b6259">
        Todos los montos están en dólares estadounidenses (USD). Este correo se envió a {{ $reserva['correo_electronico'] }}, el correo del titular de la reserva {{ $reserva['numero_reserva'] }}.
    </p>
</div>
</body>
</html>
