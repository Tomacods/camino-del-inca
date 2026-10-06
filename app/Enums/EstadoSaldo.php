<?php

namespace App\Enums;

enum EstadoSaldo: string
{
    case Adeudado = 'Adeudado';
    case Abonado = 'Abonado';
}