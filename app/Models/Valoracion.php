<?php

namespace App\Models;

use App\Enums\CategoriaValoracion;
use App\Enums\EstadoReserva;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Valoracion extends Model
{
    protected $table = 'valoracion';

    protected $primaryKey = 'id_valoracion';

    public $timestamps = false;

    protected $fillable = [
        'id_reserva',
        'fecha',
        'puntaje_experiencia_general',
        'comentario',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'datetime',
        ];
    }

    public function reserva(): BelongsTo
    {
        return $this->belongsTo(Reserva::class, 'id_reserva', 'id_reserva');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DetalleValoracion::class, 'id_valoracion', 'id_valoracion');
    }

    /* --------------------------- CU-23 Valorar servicios --------------------------- */

    public const PUNTAJE_MINIMO = 1;

    public const PUNTAJE_MAXIMO = 5;

    // R-23: se valora una reserva «Finalizada», una sola vez (la misma regla con la que Consultar Reserva ofrece la opción).
    public static function admiteValoracion(Reserva $reserva): bool
    {
        return $reserva->estado === EstadoReserva::Finalizada && ! $reserva->tieneValoracion();
    }

    // R-23: hotel, camping de etapa, transporte en bus y ferroviario, porteadores y guía; el equipo de camping, sólo si
    // algún integrante lo llevó.
    public static function getCategoriasAValorar(Reserva $reserva): array
    {
        $llevaronEquipo = $reserva->excursionistas()->where('equipo_camping', true)->exists();

        return array_values(array_filter(
            CategoriaValoracion::cases(),
            fn (CategoriaValoracion $categoria) => $categoria !== CategoriaValoracion::EquipoDeCamping || $llevaronEquipo,
        ));
    }

    // R-23: registra la valoración con un puntaje de 1 a 5 por categoría (indexado por el nombre del caso del enum, por
    // ejemplo «CampingDeEtapa»), el de la experiencia general y el comentario opcional. Con la reserva bloqueada, para que
    // dos envíos casi juntos no la valoren dos veces; la base, además, no admite dos valoraciones de la misma reserva.
    public static function valorarServicios(Reserva $reserva, array $puntajes, int $experienciaGeneral, ?string $comentario): self
    {
        return DB::transaction(function () use ($reserva, $puntajes, $experienciaGeneral, $comentario) {
            $reserva = Reserva::lockForUpdate()->findOrFail($reserva->id_reserva);

            if (! self::admiteValoracion($reserva)) {
                throw new DomainException('Sólo se valoran las reservas finalizadas, y una sola vez.');
            }

            $categorias = self::getCategoriasAValorar($reserva);

            foreach ([...array_map(fn (CategoriaValoracion $c) => $puntajes[$c->name] ?? null, $categorias), $experienciaGeneral] as $puntaje) {
                if (! is_int($puntaje) || $puntaje < self::PUNTAJE_MINIMO || $puntaje > self::PUNTAJE_MAXIMO) {
                    throw new DomainException('Cada categoría y la experiencia general llevan de 1 a 5 estrellas.');
                }
            }

            $valoracion = self::create([
                'id_reserva' => $reserva->id_reserva,
                'fecha' => now(),
                'puntaje_experiencia_general' => $experienciaGeneral,
                'comentario' => filled($comentario) ? trim($comentario) : null,
            ]);

            foreach ($categorias as $categoria) {
                $valoracion->detalles()->create([
                    'categoria' => $categoria,
                    'puntaje' => $puntajes[$categoria->name],
                ]);
            }

            return $valoracion;
        });
    }
}
