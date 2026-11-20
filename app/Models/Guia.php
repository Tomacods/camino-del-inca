<?php

namespace App\Models;

use App\Enums\EstadoPaquete;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Guia extends Model
{
    protected $table = 'guia';

    protected $primaryKey = 'id_usuario';

    // La clave es la del usuario: no la genera esta tabla.
    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'id_usuario',
        'nombre',
        'apellido',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_usuario', 'id_usuario');
    }

    public function excursiones(): HasMany
    {
        return $this->hasMany(Excursion::class, 'id_guia', 'id_usuario');
    }

    /* -------------------- CU-01 y CU-02 Crear y modificar paquete -------------------- */

    // R-01: los guías libres para una salida, ordenados por apellido. Un guía está libre si no conduce otra excursión cuyo
    // recorrido se superponga con el de esta salida, como en Excursion::buscarConflictoDeGuia: cuentan las excursiones de
    // los paquetes activos, de cualquier paquete. Al modificar una excursión que ya existe se pasa su identificación, para
    // no compararla consigo misma y que su guía siga apareciendo.
    public static function disponiblesPara(Carbon $fechaSalida, int $duracionDias, ?int $idExcursionExcluida = null): Collection
    {
        $inicio = $fechaSalida->copy()->startOfDay();
        $fin = $inicio->copy()->addDays($duracionDias - 1);

        return self::with(['excursiones' => fn ($consulta) => $consulta
            ->whereHas('paquete', fn ($paquete) => $paquete->where('estado', EstadoPaquete::Activo))
            ->when($idExcursionExcluida !== null, fn ($excursiones) => $excursiones->where('id_excursion', '!=', $idExcursionExcluida))
            ->with('paquete.recorrido'),
        ])
            ->orderBy('apellido')
            ->get()
            ->reject(fn (Guia $guia) => $guia->excursiones->contains(fn (Excursion $otra) => $otra->seSuperponeCon($inicio, $fin)))
            ->values();
    }

    /* ------------------------ CU-10 Listar integrantes ------------------------ */

    public function getNombreCompleto(): string
    {
        return $this->nombre.' '.$this->apellido;
    }
}
