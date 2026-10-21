<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Comprobante {{ $datos['numero_comprobante'] }}</title>
    <style>
        @page { margin: 24pt; }
    </style>
</head>
<body style="font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; line-height: 1.3; color: #1d1d1f; margin: 0;">
    <h1 style="font-size: 19pt; margin: 0 0 3pt;">Comprobante de pago</h1>
    <p style="margin: 0 0 10pt;">Camino del Inca</p>

    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <th style="width: 42%; text-align: left; padding: 3pt 0;">Número de comprobante</th>
            <td style="padding: 3pt 0;">{{ $datos['numero_comprobante'] }}</td>
        </tr>
        <tr>
            <th style="text-align: left; padding: 3pt 0;">Fecha y hora de emisión</th>
            <td style="padding: 3pt 0;">{{ $datos['fecha_emision']->format('d/m/Y H:i') }}</td>
        </tr>
        <tr>
            <th style="text-align: left; padding: 3pt 0;">Número de reserva</th>
            <td style="padding: 3pt 0;">{{ $datos['reserva']['numero_reserva'] }}</td>
        </tr>
        <tr>
            <th style="text-align: left; padding: 3pt 0;">Correo del titular</th>
            <td style="padding: 3pt 0; overflow-wrap: break-word;">{{ $datos['reserva']['correo_electronico'] }}</td>
        </tr>
    </table>

    <h2 style="font-size: 12pt; margin: 12pt 0 4pt;">Detalle del pago</h2>
    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <th style="width: 50%; text-align: left; padding: 3pt 0;">Precio final de la reserva</th>
            <td style="text-align: right; padding: 3pt 0;">USD {{ number_format($datos['monto_total'], 2, ',', '.') }}</td>
        </tr>
        <tr>
            <th style="text-align: left; padding: 3pt 0;">Tipo de pago</th>
            <td style="text-align: right; padding: 3pt 0;">«{{ $datos['pago']['tipo_pago']->value }}»</td>
        </tr>
        <tr>
            <th style="text-align: left; padding: 3pt 0;">Medio de pago</th>
            <td style="text-align: right; padding: 3pt 0;">{{ $datos['pago']['medio_pago'] }}</td>
        </tr>
        <tr>
            <th style="text-align: left; padding: 4pt 0; border-top: 1px solid #d2d2d7;">Monto de este pago</th>
            <td style="text-align: right; padding: 4pt 0; border-top: 1px solid #d2d2d7;">USD {{ number_format($datos['pago']['monto'], 2, ',', '.') }}</td>
        </tr>
        <tr>
            <th style="text-align: left; padding: 3pt 0;">Saldo después de este pago</th>
            <td style="text-align: right; padding: 3pt 0;">USD {{ number_format($datos['saldo_despues_pago'], 2, ',', '.') }}</td>
        </tr>
    </table>

    <h2 style="font-size: 12pt; margin: 12pt 0 4pt;">Detalle del viaje</h2>
    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <th style="width: 42%; text-align: left; padding: 3pt 0;">Paquete</th>
            <td style="padding: 3pt 0;">{{ $datos['excursion']['paquete'] }}</td>
        </tr>
        <tr>
            <th style="text-align: left; padding: 3pt 0;">Recorrido</th>
            <td style="padding: 3pt 0;">{{ $datos['paquete']['recorrido']['nombre_recorrido'] }}</td>
        </tr>
        <tr>
            <th style="text-align: left; padding: 3pt 0;">Duración del recorrido</th>
            <td style="padding: 3pt 0;">{{ $datos['paquete']['recorrido']['duracion_dias'] }} días</td>
        </tr>
        <tr>
            <th style="text-align: left; padding: 3pt 0;">Fecha de salida</th>
            <td style="padding: 3pt 0;">{{ $datos['excursion']['fecha_salida']->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <th style="text-align: left; padding: 3pt 0;">Noches extra en Cusco</th>
            <td style="padding: 3pt 0;">
                @if ($datos['noches_extra'] === 0)
                    Sin noches extra
                @else
                    {{ $datos['noches_extra'] }} (antes: {{ $datos['reserva']['noches_extra_antes'] }}; después: {{ $datos['reserva']['noches_extra_despues'] }})
                @endif
            </td>
        </tr>
    </table>

    <h3 style="font-size: 10pt; margin: 8pt 0 3pt;">Servicios incluidos</h3>
    <ul style="margin: 0; padding-left: 14pt;">
        @foreach ($datos['paquete']['servicios'] as $servicio)
            <li>{{ $servicio['tipo']->value }}: {{ $servicio['nombre'] }}</li>
        @endforeach
    </ul>

    <h3 style="font-size: 10pt; margin: 8pt 0 3pt;">Integrantes</h3>
    <ul style="margin: 0; padding-left: 14pt;">
        @foreach ($datos['excursionistas'] as $integrante)
            <li>{{ $integrante['nombre'] }} {{ $integrante['apellido'] }}</li>
        @endforeach
    </ul>

    <p style="font-size: 9pt; margin: 10pt 0 0; padding-top: 6pt; border-top: 1px solid #d2d2d7;">
        Los montos están en dólares estadounidenses. Mercado Pago cobra el equivalente en pesos argentinos, con su cotización del momento del pago.
    </p>
</body>
</html>
