<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
//use Illuminate\Support\Carbon;

class Pago extends Model
{
    protected $table = 'pagos';
    protected $primaryKey = 'id_pago';

     protected $guarded = [];

    protected $casts = [
        'fecha' => 'date',
        'monto' => 'float',
    ];
    
    public function consultarMonto(): float
    {
        return $this->getMonto();
    }
    
    private function getMonto(): float
    {
       return $this->monto; 
    }

    /**
     * TEMPORAL: datos de prueba hasta que existan las tablas.
     * Cuando estén, se reemplaza por una consulta real (ver más abajo).
     */



}