<?php

namespace App\Models;

use App\Enums\TipoPago;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\MercadoPagoConfig;
use RuntimeException;
use TypeError;

class Pago extends Model
{
    // Todos los montos del sistema son en dólares (CU-15, paso 5). Mercado Pago le cobra al comprador el equivalente en
    // pesos, con su cotización: el sistema no guarda pesos ni cotizaciones.
    private const MONEDA = 'USD';

    // El texto que va a pago.medio_pago según el payment_type_id que informa Mercado Pago. Son los de los seeders.
    private const MEDIOS_DE_PAGO = [
        'credit_card' => 'Tarjeta de crédito',
        'debit_card' => 'Tarjeta de débito',
        'account_money' => 'Dinero en cuenta de Mercado Pago',
        'prepaid_card' => 'Tarjeta prepaga',
    ];

    private const MEDIO_DE_PAGO_OTRO = 'Mercado Pago';

    private const ESTADO_APROBADO = 'approved';

    // Los medios en efectivo dejan el pago pendiente por días, y la retención dura minutos: no se ofrecen.
    private const TIPOS_DE_PAGO_EXCLUIDOS = ['ticket', 'atm'];

    // Mercado Pago pide la fecha de vencimiento en ISO 8601 con milisegundos y huso horario: 2026-10-08T22:21:36.000-03:00.
    private const FORMATO_FECHA_MERCADO_PAGO = 'Y-m-d\TH:i:s.vP';

    // La referencia es el identificador de la retención, este separador y el tipo de pago: «<uuid>_Sena» o
    // «<uuid>_Total». El uuid no tiene guiones bajos, así que el último separa las dos partes.
    private const SEPARADOR_REFERENCIA = '_';

    protected $table = 'pago';

    protected $primaryKey = 'id_pago';

    public $timestamps = false;

    protected $fillable = [
        'id_reserva',
        'fecha',
        'monto',
        'tipo_pago',
        'medio_pago',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'monto' => 'decimal:2',
            'tipo_pago' => TipoPago::class,
        ];
    }

    public function reserva(): BelongsTo
    {
        return $this->belongsTo(Reserva::class, 'id_reserva', 'id_reserva');
    }

    public function comprobante(): HasOne
    {
        return $this->hasOne(Comprobante::class, 'id_pago', 'id_pago');
    }

    /* ------------------------------ CU-15 Pagar ------------------------------- */

    // Es estático porque el monto se calcula antes de que exista el pago. El saldo no se calcula acá: es de CU-16 y sale
    // de Reserva::calcularSaldoPendiente().
    public static function calcularMontoAPagar(float $montoTotal, TipoPago $tipoPago): float
    {
        return match ($tipoPago) {
            TipoPago::Total => $montoTotal,
            TipoPago::Sena => round($montoTotal * config('reserva.porcentaje_sena'), 2),
            TipoPago::Saldo => throw new InvalidArgumentException('El saldo se calcula con Reserva::calcularSaldoPendiente().'),
        };
    }

    // Crea el pago de la reserva y su comprobante. El pago guarda sólo el día; el comprobante, el momento del pago.
    public static function registrarPago(Reserva $reserva, float $monto, $fecha, string $medioPago, TipoPago $tipoPago): self
    {
        $pago = $reserva->pagos()->create([
            'fecha' => Carbon::parse($fecha)->toDateString(),
            'monto' => $monto,
            'tipo_pago' => $tipoPago,
            'medio_pago' => $medioPago,
        ]);

        Comprobante::generarComprobante($pago, $fecha);

        return $pago;
    }

    // Pasos 4 y 5 (derivarPago del DS-15): le pide a Mercado Pago el cobro y devuelve la dirección a la que se manda al
    // cliente. Es estático porque el pago todavía no existe: se registra recién cuando Mercado Pago lo aprueba. Los datos
    // los arma el servidor, nunca el navegador: así el monto no se puede tocar. Si Mercado Pago rechaza el pedido o no
    // responde, lo anota en el registro y la excepción sube a quien lo llama.
    public static function derivarPago(array $datosPago, float $monto): string
    {
        self::configurarMercadoPago();

        $vence = Carbon::parse($datosPago['vence'])->format(self::FORMATO_FECHA_MERCADO_PAGO);

        try {
            $preferencia = (new PreferenceClient)->create([
                'items' => [[
                    'title' => $datosPago['descripcion'],
                    'quantity' => 1,
                    'unit_price' => $monto,
                    'currency_id' => self::MONEDA,
                ]],
                'external_reference' => $datosPago['referencia'],
                // Las tres a la misma pantalla: ella le pregunta a Mercado Pago cómo terminó el pago.
                'back_urls' => [
                    'success' => $datosPago['direccion_vuelta'],
                    'failure' => $datosPago['direccion_vuelta'],
                    'pending' => $datosPago['direccion_vuelta'],
                ],
                'auto_return' => self::ESTADO_APROBADO,
                // La retención dura minutos: el pago se aprueba o se rechaza en el momento, nunca queda pendiente.
                'binary_mode' => true,
                'payment_methods' => [
                    'excluded_payment_types' => array_map(fn ($tipo) => ['id' => $tipo], self::TIPOS_DE_PAGO_EXCLUIDOS),
                ],
                // Vence con la retención: después ya no se puede pagar.
                'expires' => true,
                'expiration_date_to' => $vence,
            ]);

            // El SDK carga sólo los datos que vinieron en la respuesta: si faltara la dirección, leerla daría un error
            // que no es una Exception y quien llama no podría avisarle al cliente.
            return $preferencia->init_point ?? throw new RuntimeException('Mercado Pago no devolvió la dirección de pago.');
        } catch (Exception $excepcion) {
            // Todo lo que se anota sobre Mercado Pago sale de Pago. Quien llama sigue recibiendo la excepción.
            Log::error('No se pudo crear el pedido de cobro en Mercado Pago: '.self::describirErrorMercadoPago($excepcion));

            throw $excepcion;
        }
    }

    // La «transacción aprobada» de los pasos 6 y 7. No está en el DS-15: ahí la pasarela contesta en el momento; con
    // Checkout Pro el cliente va a Mercado Pago y vuelve, y el sistema le pregunta por el pago. Lo que dice la dirección
    // de vuelta no se cree: decide lo que contesta Mercado Pago. Devuelve null si no responde o no conoce ese pago.
    public static function consultarTransaccion(string $idTransaccion): ?array
    {
        self::configurarMercadoPago();

        try {
            $pago = (new PaymentClient)->get((int) $idTransaccion);
        } catch (Exception $excepcion) {
            // MPApiException si contestó con un error (por ejemplo, no conoce el pago); Exception si no se pudo conectar.
            Log::warning('No se pudo consultar el pago '.$idTransaccion.' en Mercado Pago: '.self::describirErrorMercadoPago($excepcion));

            return null;
        }

        // El SDK carga sólo los datos que vinieron en la respuesta: los que faltan quedan sin valor y leerlos daría un
        // error. Con ?? se leen como null.
        return [
            'aprobada' => ($pago->status ?? null) === self::ESTADO_APROBADO,
            'referencia' => $pago->external_reference ?? null,
            'medio_pago' => self::MEDIOS_DE_PAGO[$pago->payment_type_id ?? ''] ?? self::MEDIO_DE_PAGO_OTRO,
        ];
    }

    // La referencia une el pago de Mercado Pago con la retención y con el tipo de pago. A la vuelta, las dos cosas se
    // leen de ahí y no de la sesión, que se comparte entre pestañas.
    public static function armarReferencia(string $idRetencion, TipoPago $tipoPago): string
    {
        // El nombre del caso (Sena, Total) y no su valor: «Seña» lleva ñ.
        return $idRetencion.self::SEPARADOR_REFERENCIA.$tipoPago->name;
    }

    // Devuelve id_retencion y tipo_pago (el enum), o null si la referencia no tiene el formato de armarReferencia() o el
    // tipo no es Seña ni Total.
    public static function leerReferencia(?string $referencia): ?array
    {
        if ($referencia === null || ! str_contains($referencia, self::SEPARADOR_REFERENCIA)) {
            return null;
        }

        $idRetencion = Str::beforeLast($referencia, self::SEPARADOR_REFERENCIA);
        $tipoPago = match (Str::afterLast($referencia, self::SEPARADOR_REFERENCIA)) {
            TipoPago::Sena->name => TipoPago::Sena,
            TipoPago::Total->name => TipoPago::Total,
            default => null,
        };

        if ($idRetencion === '' || $tipoPago === null) {
            return null;
        }

        return ['id_retencion' => $idRetencion, 'tipo_pago' => $tipoPago];
    }

    // El único lugar donde se le pasa el Access Token al SDK. No se escribe en el registro ni en ningún mensaje.
    private static function configurarMercadoPago(): void
    {
        MercadoPagoConfig::setAccessToken((string) config('services.mercadopago.access_token'));
    }

    // El texto de un error de Mercado Pago para el registro. Cuando la API contesta con un error, el mensaje de la
    // excepción es sólo «Api error. Check response for details»: el motivo (por ejemplo, qué campo rechazó) viene en la
    // respuesta, que no trae el Access Token.
    private static function describirErrorMercadoPago(Exception $excepcion): string
    {
        if (! $excepcion instanceof MPApiException) {
            return $excepcion->getMessage();
        }

        // Si la respuesta no era JSON (por ejemplo, una página de error), el SDK no tiene contenido y getContent(), que
        // promete un arreglo, da un error en lugar de devolver null.
        try {
            $respuesta = json_encode($excepcion->getApiResponse()->getContent(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (TypeError) {
            $respuesta = 'sin contenido';
        }

        return $excepcion->getMessage().' (HTTP '.$excepcion->getStatusCode().'): '.$respuesta;
    }
}
