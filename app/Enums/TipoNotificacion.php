<?php

namespace App\Enums;

// Los ocho tipos de Notificar Cliente (CU-13), como en la regla de negocio del Doc 3. No es una columna de la base: lo
// pasa el caso de uso que invoca la notificación.
enum TipoNotificacion: string
{
    case PrimeraCompra = 'Primera Compra';
    case PagoSaldo = 'Pago de Saldo';
    case CancelacionReserva = 'Cancelación de Reserva';
    case PermisosObtenidos = 'Permisos Obtenidos';
    case PermisosRechazados = 'Permisos Rechazados';
    case CancelacionFaltaPago = 'Cancelación por Falta de Pago';
    case EtapaFinal = 'Etapa Final';
    case CancelacionFaltaPermisos = 'Cancelación por Falta de Permisos';
}
