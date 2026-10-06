<?php

namespace App\Models;

use App\Enums\TipoServicio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Servicio extends Model
{
    protected $table = 'servicio';

    protected $primaryKey = 'id_servicio';

    public $timestamps = false;

    protected $fillable = [
        'tipo',
        'nombre',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoServicio::class,
        ];
    }

    public function paquetes(): BelongsToMany
    {
        return $this->belongsToMany(Paquete::class, 'paquete_servicio', 'id_servicio', 'id_paquete');
    }
}
