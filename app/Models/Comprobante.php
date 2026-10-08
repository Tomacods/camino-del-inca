<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Comprobante extends Model
{
    // El número del comprobante es este prefijo, un guion y el id_pago con ocho cifras: 0001-00000009.
    public const PREFIJO_NUMERO = '0001';

    private const CIFRAS_NUMERO = 8;

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

    /* ------------------------------ CU-15 Pagar ------------------------------- */

    // El número sale del id_pago: la base ya garantiza que no se repite, así que no hace falta otro contador. Coincide
    // con los comprobantes de los seeders.
    public static function generarComprobante(Pago $pago, $fechaEmision): self
    {
        return $pago->comprobante()->create([
            'numero_comprobante' => self::PREFIJO_NUMERO.'-'.str_pad((string) $pago->id_pago, self::CIFRAS_NUMERO, '0', STR_PAD_LEFT),
            'fecha_emision' => $fechaEmision,
        ]);
    }
}
