<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Comprobante extends Model
{
    protected $table = 'comprobante';

    protected $primaryKey = 'id_comprobante';

    public $timestamps = false;

    protected $fillable = [
        'id_pago',
        'numero_comprobante',
        'fecha_emision',
    ];

    protected function casts(): array
    {
        return [
            'fecha_emision' => 'datetime',
        ];
    }

    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class, 'id_pago', 'id_pago');
    }
}
