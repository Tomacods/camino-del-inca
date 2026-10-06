<?php

namespace App\Enums;

enum EstadoReserva: string
{
    case Pendiente = 'Pendiente';
    case Confirmada = 'Confirmada';
    case SinPermiso = 'Sin Permiso';
    case Cancelada = 'Cancelada';
    case Finalizada = 'Finalizada';
}
