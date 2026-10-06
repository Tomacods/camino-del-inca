<?php

namespace App\Enums;

enum EstadoPermiso: string
{
    case Pendiente = 'Pendiente';
    case Obtenido = 'Obtenido';
    case NoObtenido = 'No Obtenido';
}
