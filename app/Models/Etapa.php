<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Etapa extends Model
{
    protected $table = 'etapa';

    protected $primaryKey = 'id_etapa';

    public $timestamps = false;

    protected $fillable = [
        'id_recorrido',
        'nombre',
        'orden',
    ];

    public function recorrido(): BelongsTo
    {
        return $this->belongsTo(Recorrido::class, 'id_recorrido', 'id_recorrido');
    }

    // Excursiones que tienen a esta etapa como etapa actual.
    public function excursiones(): HasMany
    {
        return $this->hasMany(Excursion::class, 'id_etapa_actual', 'id_etapa');
    }
}
