<?php

namespace App\Enums;

enum CategoriaValoracion: string
{
    case Hotel = 'Hotel';
    case CampingDeEtapa = 'Camping de Etapa';
    case TransporteEnBus = 'Transporte en Bus';
    case TransporteFerroviario = 'Transporte Ferroviario';
    case Porteadores = 'Porteadores';
    case Guia = 'Guía';
    case EquipoDeCamping = 'Equipo de Camping';
}
