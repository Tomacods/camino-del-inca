<?php

namespace App\Enums;

enum TipoPago: string
{
    case Sena = 'Seña';
    case Saldo = 'Saldo';
    case Total = 'Total';
}
