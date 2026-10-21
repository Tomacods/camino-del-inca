<?php

namespace App\Jobs;

use App\Models\Excursion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// Devuelve a la excursión las plazas de una retención (CU-14). Se usa en tres momentos: al vencer el plazo (demorada,
// corre aunque el cliente se haya ido), al cancelar y al pagar (en el momento). Mientras dura la marca de «ya
// liberada» (30 días), cada retención se libera una sola vez: si el cliente canceló o pagó, la demorada llega después y
// no descuenta de nuevo.
class LiberarCupoRetenido implements ShouldQueue
{
    use Queueable;

    // Cuánto dura la marca de «ya liberada». La demorada puede llegar mucho después del plazo de la retención: si la
    // cola estuvo detenida, o si alguien reintenta a mano una tarea fallida. Sin la marca descontaría plazas que ya son
    // de otros clientes. Lleva vencimiento porque sólo así Cache::add() usa la inserción atómica de la base
    // (insertOrIgnore); sin vencimiento lee y después escribe, y dos tareas al mismo tiempo podrían liberar las dos.
    // Con la caché en la base, una fila vencida se borra recién cuando se vuelve a leer su clave: queda una fila chica
    // por retención, que para este sistema no pesa.
    private const DIAS_MARCA_LIBERADA = 30;

    public function __construct(
        public int $idExcursion,
        public int $cantidadPlazas,
        public string $idRetencion,
    ) {}

    // La marca y la liberación van en una transacción: con la caché en la base, como en .env.example, si la liberación
    // falla tampoco queda la marca, y la tarea se puede reintentar.
    public function handle(): void
    {
        DB::transaction(function () {
            // add() sólo guarda si la clave no existía, y dice si la guardó: la primera vez da verdadero y las
            // siguientes, falso.
            $primeraVez = Cache::add(self::claveMarcaLiberada($this->idRetencion), true, now()->addDays(self::DIAS_MARCA_LIBERADA));

            if ($primeraVez) {
                Excursion::findOrFail($this->idExcursion)->liberarCupoRetenido($this->cantidadPlazas);
            }
        });
    }

    // Si la retención ya se liberó, por vencimiento, por cancelación o por un pago (CU-15). Lee la misma marca que
    // deja handle().
    public static function yaLiberada(string $idRetencion): bool
    {
        return Cache::has(self::claveMarcaLiberada($idRetencion));
    }

    // La clave de la marca se arma sólo acá, así handle() y yaLiberada() leen siempre la misma.
    private static function claveMarcaLiberada(string $idRetencion): string
    {
        return 'retencion-liberada:'.$idRetencion;
    }
}
