<?php

namespace App\Enums;

enum TipoServicio: string
{
    case Hotel = 'Hotel';
    case TransporteEnBus = 'Transporte en Bus';
    case TransporteFerroviario = 'Transporte Ferroviario';
    case CampingDeEtapa = 'Camping de Etapa';
}
