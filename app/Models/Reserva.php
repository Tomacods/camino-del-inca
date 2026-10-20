<?php

namespace App\Models;

use App\Enums\EstadoPermiso;
use App\Enums\EstadoReserva;
use App\Enums\EstadoSaldo;
use App\Enums\MotivoDevolucion;
use App\Enums\TipoNotificacion;
use App\Enums\TipoPago;
use App\Enums\TipoServicio;
use App\Jobs\LiberarCupoRetenido;
use App\Mail\NotificacionCliente;
use Closure;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class Reserva extends Model
{
    public const OPCION_PAGAR_SALDO = 'Pagar saldo';

    public const OPCION_MODIFICAR = 'Modificar reserva';

    public const OPCION_CANCELAR = 'Cancelar reserva';

    public const OPCION_REINTEGRO = 'Solicitar reintegro';

    public const OPCION_REPROGRAMAR = 'Solicitar reprogramación';

    public const OPCION_VALORAR = 'Valorar servicios';

    // Número de reserva (CU-15): seis cifras, guion y dígito verificador, módulo 11 con pesos 2 a 7 desde la derecha.
    private const CIFRAS_NUMERO_RESERVA = 6;

    private const PESOS_DIGITO_VERIFICADOR = [2, 3, 4, 5, 6, 7];

    // Candado de generarReserva() (CU-15). Se suelta solo a los 10 segundos, por si el proceso se cae con el candado
    // tomado; un pago espera hasta 5 a que se suelte.
    private const CANDADO_GENERAR_RESERVA = 'generar-reserva';

    private const SEGUNDOS_CANDADO = 10;

    private const SEGUNDOS_ESPERA_CANDADO = 5;

    // Las que todavía esperan la confirmación de los permisos (CU-26): «Pendiente», sin validar (CU-04), y «Sin Permiso»,
    // sin reintegro (CU-17) ni reprogramación (CU-19). Si la fecha límite vence en uno de estos estados, se cancela.
    private const ESTADOS_SIN_CONFIRMAR = [EstadoReserva::Pendiente, EstadoReserva::SinPermiso];

    protected $table = 'reserva';

    protected $primaryKey = 'id_reserva';

    public $timestamps = false;

    protected $fillable = [
        'id_excursion',
        'numero_reserva',
        'correo_electronico',
        'fecha_reserva',
        'estado',
        'estado_saldo',
        'noches_extra_antes',
        'noches_extra_despues',
        'fecha_limite_saldo',
        'fecha_limite_confirmacion',
    ];

    protected function casts(): array
    {
        return [
            'fecha_reserva' => 'datetime',
            'fecha_limite_saldo' => 'datetime',
            'fecha_limite_confirmacion' => 'datetime',
            'estado' => EstadoReserva::class,
            'estado_saldo' => EstadoSaldo::class,
        ];
    }

    public function excursion(): BelongsTo
    {
        return $this->belongsTo(Excursion::class, 'id_excursion', 'id_excursion');
    }

    public function excursionistas(): HasMany
    {
        return $this->hasMany(Excursionista::class, 'id_reserva', 'id_reserva');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class, 'id_reserva', 'id_reserva');
    }

    public function devolucion(): HasOne
    {
        return $this->hasOne(Devolucion::class, 'id_reserva', 'id_reserva');
    }

    public function valoracion(): HasOne
    {
        return $this->hasOne(Valoracion::class, 'id_reserva', 'id_reserva');
    }

    /* ----------------------------- CU-14 Realizar ----------------------------- */

    // Es estático porque cuando el cliente elige las noches todavía no existe la reserva: se guarda con el primer pago.
    public static function validarNochesExtra(int $nochesExtraAntes, int $nochesExtraDespues): bool
    {
        return $nochesExtraAntes >= 0
            && $nochesExtraDespues >= 0
            && $nochesExtraAntes + $nochesExtraDespues <= config('reserva.maximo_noches_extra');
    }

    /* ------------------------------ CU-15 Pagar ------------------------------- */

    // Pasos 8 a 11 con el primer pago aprobado: registra la reserva con sus integrantes, el pago con su comprobante y
    // pasa las plazas de retenidas a reservadas. Recibe la reserva en curso que CU-14 guardó en la sesión; no la lee de
    // ahí: se la pasa quien la llama. Es estático porque la reserva todavía no existe. Una retención genera una sola
    // reserva: devuelve null si la retención venció (A3) o si ya se liberó.
    public static function generarReserva(array $reservaEnCurso, TipoPago $tipoPago, string $medioPago): ?self
    {
        if ($tipoPago === TipoPago::Saldo) {
            throw new InvalidArgumentException('El saldo se paga sobre una reserva ya registrada (CU-16).');
        }

        // A3: si la retención venció, no se crea nada. Decide la hora del servidor, igual que en la pantalla de pago; de
        // liberar esas plazas ya se ocupan la tarea demorada y la pantalla. Este primer control es para salir rápido,
        // sin esperar el candado.
        $vence = Carbon::parse($reservaEnCurso['vence']);

        if (now()->greaterThanOrEqualTo($vence)) {
            return null;
        }

        // El número siguiente se calcula mirando el último emitido: si dos clientes pagan a la vez, los dos verían el
        // mismo. Con el candado, mientras una reserva se registra la siguiente espera su turno. Si no lo consigue en el
        // plazo, block() lanza una excepción y no se registra nada.
        $candado = Cache::lock(self::CANDADO_GENERAR_RESERVA, self::SEGUNDOS_CANDADO);
        $candado->block(self::SEGUNDOS_ESPERA_CANDADO);

        try {
            // La hora se toma con el candado ya tomado: es el momento en que se registran la reserva, el pago y el
            // comprobante, los tres con la misma.
            $ahora = now();

            // Se controla de nuevo, porque mientras se esperaba el candado la retención pudo vencer o liberarse: la
            // canceló el cliente, la liberó la tarea demorada o la usó un pago anterior. Si el mismo pago llega dos
            // veces, la segunda llamada espera a que la primera termine, encuentra la retención liberada y no
            // registra otra reserva.
            if ($ahora->greaterThanOrEqualTo($vence) || LiberarCupoRetenido::yaLiberada($reservaEnCurso['id_retencion'])) {
                return null;
            }

            // Todo en una sola transacción: o queda todo o no queda nada.
            return DB::transaction(function () use ($reservaEnCurso, $tipoPago, $medioPago, $ahora) {
                $excursion = Excursion::findOrFail($reservaEnCurso['id_excursion']);

                // En el DS-15 el pago va antes que la reserva, pero en la base el pago lleva la clave de la reserva
                // (pago.id_reserva no admite nulos): se guarda primero la reserva.
                $reserva = new self([
                    'correo_electronico' => $reservaEnCurso['correo_electronico'],
                    'noches_extra_antes' => $reservaEnCurso['noches_extra_antes'],
                    'noches_extra_despues' => $reservaEnCurso['noches_extra_despues'],
                    'fecha_reserva' => $ahora,
                    'numero_reserva' => self::generarNumeroReserva(),
                    'estado' => EstadoReserva::Pendiente,
                    'estado_saldo' => $tipoPago === TipoPago::Total ? EstadoSaldo::Abonado : EstadoSaldo::Adeudado,
                ]);
                $reserva->fijarFechasLimite($excursion->getFechaSalida());
                $excursion->agregarReserva($reserva);

                foreach ($reservaEnCurso['integrantes'] as $integrante) {
                    $reserva->excursionistas()->create([
                        'nombre' => $integrante['nombre'],
                        'apellido' => $integrante['apellido'],
                        'documento_pasaporte' => $integrante['documento_pasaporte'],
                        'equipo_camping' => $integrante['equipo_camping'],
                        'estado_permiso' => EstadoPermiso::Pendiente,
                    ]);
                }

                $monto = Pago::calcularMontoAPagar($reserva->calcularMontoTotal(), $tipoPago);
                Pago::registrarPago($reserva, $monto, $ahora, $medioPago, $tipoPago);

                // Se libera con la misma tarea que la demorada y dentro de la transacción: cuando la demorada llegue, no
                // descuenta de nuevo. Las plazas pasan de retenidas a reservadas: el cupo disponible queda igual. La
                // marca de «ya liberada» se guarda con la transacción, antes de soltar el candado: un segundo pago con
                // esta retención ya la encuentra.
                LiberarCupoRetenido::dispatchSync($excursion->id_excursion, count($reservaEnCurso['integrantes']), $reservaEnCurso['id_retencion']);

                return $reserva;
            });
        } finally {
            // Se suelta aunque la transacción falle: si no, el pago siguiente tendría que esperar a que venza solo.
            $candado->release();
        }
    }

    // Cada cifra, desde la derecha, se multiplica por su peso (2, 3, ..., 7 y vuelve a empezar); el dígito es el resto
    // de dividir la suma por 11. Devuelve de 0 a 10: quien lo usa decide qué hacer con el 10.
    public static function calcularDigitoVerificador(int $numero): int
    {
        $cifras = array_reverse(str_split((string) $numero));
        $pesos = self::PESOS_DIGITO_VERIFICADOR;
        $suma = 0;

        foreach ($cifras as $posicion => $cifra) {
            $suma += (int) $cifra * $pesos[$posicion % count($pesos)];
        }

        return $suma % 11;
    }

    // El secuencial sigue al del último número emitido; sin reservas, empieza en 1. Como todos tienen seis cifras con
    // ceros a la izquierda, ordenarlos como texto es ordenarlos como números.
    public static function generarNumeroReserva(): string
    {
        $ultimoNumero = self::orderByDesc('numero_reserva')->value('numero_reserva');
        $secuencial = $ultimoNumero === null ? 1 : (int) Str::before($ultimoNumero, '-') + 1;

        // Un resto de 10 no entra en una cifra: esos números no se emiten.
        while (self::calcularDigitoVerificador($secuencial) === 10) {
            $secuencial++;
        }

        return str_pad((string) $secuencial, self::CIFRAS_NUMERO_RESERVA, '0', STR_PAD_LEFT)
            .'-'.self::calcularDigitoVerificador($secuencial);
    }

    // Vencen a las 23:59:59, como en los seeders. subMonthsNoOverflow no se pasa al mes siguiente: del 29/03 va al 28/02
    // y no al 01/03. Carbon::parse hace una copia, así no cambia la fecha de salida de la excursión. Usa estado_saldo:
    // se llama después de asignarlo (en el DS-15 va antes de setEstadoSaldo). Sólo asigna: guarda quien la llama.
    public function fijarFechasLimite($fechaSalida): void
    {
        $fechaLimite = self::calcularFechaLimite($fechaSalida);

        $this->fecha_limite_confirmacion = $fechaLimite;
        $this->fecha_limite_saldo = $this->estado_saldo === EstadoSaldo::Adeudado ? $fechaLimite : null;
    }

    /* ----------------------------- CU-18 Consultar ---------------------------- */

    // A1. El dígito tiene que ser el que calcularDigitoVerificador() da para las seis cifras. Un número con otro
    // formato tampoco es válido.
    public static function validarNumeroReserva(string $numeroReserva): bool
    {
        if (preg_match('/^\d{'.self::CIFRAS_NUMERO_RESERVA.'}-\d$/', $numeroReserva) !== 1) {
            return false;
        }

        [$cifras, $digito] = explode('-', $numeroReserva);

        return self::calcularDigitoVerificador((int) $cifras) === (int) $digito;
    }

    // Los correos se guardan en minúsculas y sin espacios en las puntas: el que escribe el cliente se convierte igual,
    // así encuentra su reserva aunque lo escriba con otras mayúsculas.
    public static function buscarPorCorreoYNumero(string $correo, string $numeroReserva): ?self
    {
        return self::where('correo_electronico', mb_strtolower(trim($correo)))
            ->where('numero_reserva', $numeroReserva)
            ->first();
    }

    public function calcularMontoTotal(): float
    {
        return $this->excursion->paquete->calcularMonto(
            $this->excursionistas->count(),
            $this->noches_extra_antes + $this->noches_extra_despues,
            $this->excursionistas->where('equipo_camping', true)->count(),
        );
    }

    public function getDetalle(): self
    {
        return $this->load([
            'excursion.paquete',
            'excursionistas',
            'pagos' => fn ($pagos) => $pagos->orderBy('fecha'),
        ]);
    }

    public function calcularSaldoPendiente(): float
    {
        return $this->calcularMontoTotal() - $this->sumarPagos();
    }

    // «Modificar reserva» se ofrece con la misma anticipación que exige iniciarModificacion() (CU-20): a menos de 3 meses
    // de la salida, la pantalla de modificar la rechazaría. «Solicitar reintegro» y «Solicitar reprogramación», con la
    // misma regla que exigen calcularReintegro() (CU-17) e iniciarReprogramacion() (CU-19): con el plazo vencido, las dos
    // pantallas la rechazarían.
    public function getOpcionesHabilitadas(): array
    {
        return $this->determinarOpciones(
            $this->estado,
            $this->estado_saldo,
            $this->tieneValoracion(),
            $this->excursion->cumpleAnticipacionMinima(now()),
            $this->admiteReintegro(),
            $this->admiteReprogramacion(),
        );
    }

    public function tieneValoracion(): bool
    {
        return $this->valoracion()->exists();
    }

    private function determinarOpciones(EstadoReserva $estado, EstadoSaldo $estadoSaldo, bool $tieneValoracion, bool $cumpleAnticipacionMinima, bool $admiteReintegro, bool $admiteReprogramacion): array
    {
        return match ($estado) {
            EstadoReserva::Confirmada => array_values(array_filter([
                $estadoSaldo === EstadoSaldo::Adeudado ? self::OPCION_PAGAR_SALDO : null,
                $cumpleAnticipacionMinima ? self::OPCION_MODIFICAR : null,
                self::OPCION_CANCELAR,
            ])),
            EstadoReserva::Pendiente => [self::OPCION_CANCELAR],
            EstadoReserva::SinPermiso => array_values(array_filter([
                $admiteReintegro ? self::OPCION_REINTEGRO : null,
                $admiteReprogramacion ? self::OPCION_REPROGRAMAR : null,
            ])),
            EstadoReserva::Finalizada => $tieneValoracion ? [] : [self::OPCION_VALORAR],
            default => [], // Cancelada
        };
    }

    /* --------------------------- CU-16 Pagar saldo ---------------------------- */

    // Pasos 2 a 4 (iniciarPagoSaldo del DS-16): devuelve el saldo pendiente y la fecha límite para pagarlo. Si el saldo no
    // se puede pagar, lanza DomainException con el aviso para el cliente, como iniciarModificacion() de CU-20. El saldo
    // se redondea a dos decimales: es lo que se le pide a Mercado Pago.
    public function iniciarPagoSaldo(): array
    {
        $saldoPendiente = round($this->calcularSaldoPendiente(), 2);

        // A2. El saldo se mira además de estado_saldo: una reserva «Adeudado» sin nada que pagar no va a Mercado Pago.
        if (! $this->validarEstado([EstadoReserva::Confirmada])
            || $this->estado_saldo !== EstadoSaldo::Adeudado
            || $saldoPendiente <= 0) {
            throw new DomainException('Esta reserva no tiene saldo pendiente.');
        }

        // A3. Sólo se informa: la cancelación por falta de pago es de CU-22. Una reserva con saldo adeudado siempre tiene
        // fecha límite; si faltara, el aviso sale sin la fecha.
        if (! $this->verificarFechaLimiteSaldo()) {
            $fechaLimite = $this->fecha_limite_saldo === null ? '' : ' el '.$this->fecha_limite_saldo->format('d/m/Y').' a las '.$this->fecha_limite_saldo->format('H:i');

            throw new DomainException('El plazo para pagar el saldo venció'.$fechaLimite.' y la reserva se cancela por falta de pago.');
        }

        return [
            'saldo_pendiente' => $saldoPendiente,
            'fecha_limite_saldo' => $this->fecha_limite_saldo,
        ];
    }

    // Pública porque también la usa la cancelación por falta de pago (CU-22), que le pasa la hora de la verificación;
    // CU-16 la llama sin hora y compara con la actual. La fecha límite vence a las 23:59:59 de ese día, como la guardan
    // los seeders y fijarFechasLimite(), salvo que los permisos se hayan validado el último día: ahí es la de las 72 horas
    // (fijarFechaLimiteSaldoConfirmada()). Hasta ese segundo, todavía se puede pagar.
    public function verificarFechaLimiteSaldo(?Carbon $fecha_hora = null): bool
    {
        return $this->fecha_limite_saldo !== null && ($fecha_hora ?? now())->lessThanOrEqualTo($this->fecha_limite_saldo);
    }

    // Paso 10 (actualizarReserva del DS-16): el saldo pasa a «Abonado». setEstadoSaldo del DS-16 es esta asignación. El
    // pago se recibe como en el diagrama, aunque para esto no hace falta. fecha_limite_saldo no se borra: queda como
    // dato, así que CU-22 tiene que buscar las reservas por estado_saldo y no sólo por la fecha.
    public function actualizarReserva(Pago $pago): void
    {
        $this->estado_saldo = EstadoSaldo::Abonado;
        $this->save();
    }

    /* --------------------- CU-22 Cancelar por falta de pago ------------------- */

    // El mensaje del Temporizador (DS-22), pasos 1 a 5. Cada reserva se cancela y se notifica por separado: si una falla,
    // se anota en el registro y se sigue con las demás; la que falló vuelve a aparecer en la próxima verificación. El
    // correo se pide después de guardar y fuera de la transacción, como en CU-21; si falla, notificarCliente() lo anota
    // y la cancelación queda igual (A8 de CU-13). Devuelve cuántas reservas canceló: 0 en A1. La hora que recibe vale
    // para todo el recorrido: con ella se buscan las reservas y se vuelve a comprobar cada una.
    public static function verificarVencimientosDePago(Carbon $fecha_hora_actual): int
    {
        $canceladas = 0;

        foreach (self::buscarSaldosVencidos($fecha_hora_actual) as $reserva) {
            try {
                if (! $reserva->ejecutarCancelacionSinDevolucion($fecha_hora_actual)) {
                    continue;
                }
            } catch (Throwable $excepcion) {
                Log::error('No se pudo cancelar por falta de pago la reserva '.$reserva->numero_reserva.': '.$excepcion->getMessage());

                continue;
            }

            $canceladas++;
            self::notificarCliente(TipoNotificacion::CancelacionFaltaPago, $reserva->numero_reserva);
        }

        return $canceladas;
    }

    // Paso 2. Es la regla de tieneSaldoVencido() escrita en SQL: vencida es anterior a la hora recibida, así que en el
    // último segundo del plazo todavía no aparece y en el siguiente, sí. Una fecha nula no cumple el «<».
    // Laravel pasa la hora como «Y-m-d H:i:s», sin fracciones de segundo, igual en PostgreSQL que en SQLite: nunca toma
    // una reserva antes que verificarFechaLimiteSaldo(). Hay que filtrar por estado_saldo porque fecha_limite_saldo no se
    // borra al pagar el saldo (actualizarReserva()).
    public static function buscarSaldosVencidos(Carbon $fecha_hora_actual): Collection
    {
        return self::where('estado', EstadoReserva::Confirmada)
            ->where('estado_saldo', EstadoSaldo::Adeudado)
            ->where('fecha_limite_saldo', '<', $fecha_hora_actual)
            ->orderBy('id_reserva')
            ->get();
    }

    // Pasos 3 y 4: pasa a «Cancelada» sin registrar devolución (la seña no se reembolsa). El cupo no se toca: una reserva
    // cancelada deja de contar en Excursion::sumarPlazasReservadas(). Se vuelve a leer la reserva con su fila bloqueada,
    // como en Pago::registrarPagoSaldo(), que bloquea la misma: si el cliente paga el saldo mientras tanto, una de las
    // dos espera a la otra. Por eso todo se comprueba de nuevo adentro, con la misma hora con la que se buscó; si ya no
    // corresponde (pagó, se canceló o la canceló otra verificación que corría a la vez), no hace nada. Devuelve si la
    // canceló.
    //
    // Cruce con CU-16: el cobro del saldo en Mercado Pago vence, como tarde, con la fecha límite, pero el pago se
    // registra recién cuando el cliente vuelve al sitio. Si pagó a último momento y vuelve después de esta cancelación,
    // registrarPagoSaldo() ya no lo registra y la devolución es manual, con la agencia, como en el cruce de CU-20
    // (decisión del grupo del 13/09).
    public function ejecutarCancelacionSinDevolucion(Carbon $fecha_hora_actual): bool
    {
        $cancelada = DB::transaction(function () use ($fecha_hora_actual) {
            $actual = self::lockForUpdate()->find($this->id_reserva);

            if ($actual === null || ! $actual->tieneSaldoVencido($fecha_hora_actual)) {
                return false;
            }

            $actual->estado = EstadoReserva::Cancelada;
            $actual->save();

            return true;
        });

        $this->refresh();

        return $cancelada;
    }

    // La condición de CU-22 para una reserva ya leída, a la hora de la verificación. La fecha la decide
    // verificarFechaLimiteSaldo(), la misma regla que usa CU-16 para no dejar pagar.
    private function tieneSaldoVencido(Carbon $fecha_hora_actual): bool
    {
        return $this->validarEstado([EstadoReserva::Confirmada])
            && $this->estado_saldo === EstadoSaldo::Adeudado
            && ! $this->verificarFechaLimiteSaldo($fecha_hora_actual);
    }

    /* ------------------------- CU-04 Validar permisos ------------------------- */

    public static function buscarReserva(string $numeroReserva): ?self
    {
        return self::firstWhere('numero_reserva', $numeroReserva);
    }

    // Paso 2 y A3: devuelve los datos de los integrantes si la reserva todavía admite la validación. Si no, lanza
    // DomainException con el aviso para el administrador, como iniciarPagoSaldo() de CU-16. Además de A3, se controla la
    // fecha límite de confirmación: la regla de negocio no admite validar una reserva con el plazo vencido.
    public function iniciarValidacionPermisos(): array
    {
        $this->verificarAdmiteValidacionPermisos();

        return $this->getExcursionistas()
            ->map(fn (Excursionista $excursionista) => $excursionista->consultarDatosExcursionistas())
            ->all();
    }

    // Devuelve la relación ya cargada: registrarPermiso() y todosLosPermisosObtenidos() trabajan sobre los mismos
    // integrantes, así el resultado ve los permisos recién registrados sin volver a leerlos.
    public function getExcursionistas(): Collection
    {
        return $this->excursionistas;
    }

    // Vence a las 23:59:59 de ese día, como fecha_limite_saldo: hasta ese segundo, todavía se puede validar. Si la
    // reserva pasó a «Sin Permiso» el último día, la fecha guardada es la de las 72 horas (fijarFechaLimiteSinPermiso()).
    // Es el único lugar que dice cuándo vence el plazo: también lo usa la cancelación por falta de confirmación (CU-26),
    // que le pasa la hora que recibe del Temporizador. CU-04 no la pasa y usa la actual.
    public function verificarFechaLimiteConfirmacion(?Carbon $fecha_hora = null): bool
    {
        return ($fecha_hora ?? now())->lessThanOrEqualTo($this->fecha_limite_confirmacion);
    }

    // Paso 3, para un integrante. Sólo asigna y guarda el permiso: la reserva cambia de estado en
    // ejecutarValidacionPermisos(). «Pendiente» no es un resultado de la validación.
    public function registrarPermiso(string $documentoPasaporte, EstadoPermiso $estadoPermiso): void
    {
        $this->verificarAdmiteValidacionPermisos();

        if ($estadoPermiso === EstadoPermiso::Pendiente) {
            throw new DomainException('El permiso sólo se puede registrar como «'.EstadoPermiso::Obtenido->value.'» o «'.EstadoPermiso::NoObtenido->value.'».');
        }

        // Comparación estricta: con == PHP toma «012345» y «12345» como el mismo número.
        $excursionista = $this->getExcursionistas()
            ->first(fn (Excursionista $integrante) => $integrante->documento_pasaporte === $documentoPasaporte);

        if ($excursionista === null) {
            throw new DomainException('El documento '.$documentoPasaporte.' no es de un integrante de la reserva '.$this->numero_reserva.'.');
        }

        $excursionista->actualizarPermiso($estadoPermiso);
    }

    // Pasos 4 y 5 y A1. No hay confirmaciones parciales: si a algún integrante todavía no se le registró el permiso, no
    // cambia nada. El cupo no se toca: «Sin Permiso» sigue ocupando lugar en la excursión.
    public function ejecutarValidacionPermisos(): void
    {
        $this->verificarAdmiteValidacionPermisos();

        if ($this->getExcursionistas()->contains(fn (Excursionista $excursionista) => $excursionista->estado_permiso === EstadoPermiso::Pendiente)) {
            throw new DomainException('Falta registrar el permiso de algún integrante de la reserva '.$this->numero_reserva.'.');
        }

        $this->estado = $this->todosLosPermisosObtenidos() ? EstadoReserva::Confirmada : EstadoReserva::SinPermiso;

        if ($this->estado === EstadoReserva::SinPermiso) {
            $this->fijarFechaLimiteSinPermiso(now());
        }

        if ($this->estado === EstadoReserva::Confirmada) {
            $this->fijarFechaLimiteSaldoConfirmada(now());
        }

        $this->save();
    }

    // Regla de CU-26: si la reserva pasa a «Sin Permiso» con menos de un mes de anticipación, el plazo para elegir entre
    // el reintegro (CU-17) y la reprogramación (CU-19) vence a las 72 horas de la notificación o el día anterior a la
    // salida, lo que ocurra primero. Como CU-04 no valida después de la fecha límite, sólo pasa si se valida el último
    // día: sin esta regla, el cliente tendría hasta las 23:59:59 de ese mismo día. El plazo se guarda en
    // fecha_limite_confirmacion, la fecha con la que CU-17 y CU-19 dejan de admitir el pedido y CU-26 cancela.
    private function fijarFechaLimiteSinPermiso(Carbon $fecha_hora): void
    {
        $plazo = $this->calcularPlazoExcepcional($fecha_hora);

        if ($plazo !== null) {
            $this->fecha_limite_confirmacion = $plazo;
        }
    }

    // Regla de CU-16: el saldo se paga hasta un mes antes de la salida, pero si los permisos se validaron con menos de un
    // mes de anticipación, el plazo vence a las 72 horas de la notificación o el día anterior a la salida, lo que ocurra
    // primero. Como en «Sin Permiso», sólo pasa si se valida el último día: sin esta regla, el cliente tendría hasta las
    // 23:59:59 de ese mismo día para pagar. El plazo se guarda en fecha_limite_saldo, la fecha con la que CU-16 deja de
    // admitir el pago y CU-22 cancela. Con el saldo «Abonado» no hay nada que pagar y fecha_limite_saldo sigue en nulo.
    // fecha_limite_confirmacion no cambia: la reserva ya quedó confirmada.
    private function fijarFechaLimiteSaldoConfirmada(Carbon $fecha_hora): void
    {
        if ($this->estado_saldo !== EstadoSaldo::Adeudado) {
            return;
        }

        $plazo = $this->calcularPlazoExcepcional($fecha_hora);

        if ($plazo !== null) {
            $this->fecha_limite_saldo = $plazo;
        }
    }

    // El plazo de las reglas de CU-16 y CU-26 para una validación hecha a esa hora: las 72 horas o las 23:59:59 del día
    // anterior a la salida, lo que ocurra primero. Devuelve null si se validó con un mes o más de anticipación, es decir,
    // hasta las 00:00:00 del día límite: ahí siguen valiendo las fechas de fijarFechasLimite(). El correo sale apenas se
    // guarda la validación (paso 6), así que se cuenta desde la hora de la validación. Con un mes de anticipación, las 72
    // horas siempre llegan antes que el día anterior a la salida, pero se compara igual porque la regla lo dice.
    private function calcularPlazoExcepcional(Carbon $fecha_hora): ?Carbon
    {
        $fechaSalida = $this->excursion->getFechaSalida();
        $unMesAntes = $fechaSalida->copy()->subMonthsNoOverflow(config('reserva.meses_anticipacion_fechas_limite'));

        if ($fecha_hora->lessThanOrEqualTo($unMesAntes)) {
            return null;
        }

        return $fecha_hora->copy()
            ->addHours(config('reserva.horas_plazo_excepcional'))
            ->min($fechaSalida->copy()->subDay()->endOfDay());
    }

    // Paso 6 y A1: el correo de Notificar Cliente (CU-13) que corresponde al resultado de la validación. Usa la misma
    // regla que el cambio de estado.
    public function getTipoNotificacionValidacion(): TipoNotificacion
    {
        return $this->todosLosPermisosObtenidos() ? TipoNotificacion::PermisosObtenidos : TipoNotificacion::PermisosRechazados;
    }

    public function todosLosPermisosObtenidos(): bool
    {
        return self::sonTodosObtenidos($this->getExcursionistas()->pluck('estado_permiso')->all());
    }

    // Para la línea que adelanta el resultado en la pantalla, antes de confirmar: el estado en que quedaría la reserva con
    // permisos que todavía no se guardaron. Usa la misma regla que todosLosPermisosObtenidos().
    public static function anticiparResultadoValidacion(array $estadosPermiso): EstadoReserva
    {
        return self::sonTodosObtenidos($estadosPermiso) ? EstadoReserva::Confirmada : EstadoReserva::SinPermiso;
    }

    // Sólo CU-04 saca a una reserva de «Pendiente» hacia «Confirmada» o «Sin Permiso»: si está en uno de esos dos, sus
    // permisos ya se validaron. La pantalla lo usa para no avisar un error cuando la misma validación llega dos veces.
    public function tienePermisosValidados(): bool
    {
        return $this->validarEstado([EstadoReserva::Confirmada, EstadoReserva::SinPermiso]);
    }

    // La regla de la validación, en un solo lugar para los permisos guardados y los que todavía no se guardaron.
    private static function sonTodosObtenidos(array $estadosPermiso): bool
    {
        return collect($estadosPermiso)->every(fn (EstadoPermiso $estadoPermiso) => $estadoPermiso === EstadoPermiso::Obtenido);
    }

    // A3 y plazo vencido. Lo controlan los tres pasos que escriben o muestran la validación, no sólo el primero: así una
    // reserva «Cancelada» no puede volver a «Confirmada» aunque alguien llame a registrarPermiso() o a
    // ejecutarValidacionPermisos() sin pasar antes por iniciarValidacionPermisos().
    private function verificarAdmiteValidacionPermisos(): void
    {
        if (! $this->validarEstado([EstadoReserva::Pendiente])) {
            throw new DomainException('La reserva '.$this->numero_reserva.' está en «'.$this->estado->value.'» y ya no admite validación de permisos.');
        }

        if (! $this->verificarFechaLimiteConfirmacion()) {
            throw new DomainException('El plazo para confirmar los permisos de la reserva '.$this->numero_reserva.' venció el '
                .$this->fecha_limite_confirmacion->format('d/m/Y').': ya no admite validación de permisos.');
        }
    }

    /* ---------------- CU-26 Cancelar por falta de confirmación ---------------- */

    // El mensaje del Temporizador (DS-26), pasos 1 a 5. Cada reserva se cancela y se notifica por separado: si una falla,
    // se anota en el registro con su número y se sigue con las demás; la que falló vuelve a aparecer en la próxima
    // verificación. El correo se pide después de guardar y fuera de la transacción, como en CU-17; si falla,
    // notificarCliente() lo anota y la cancelación queda igual (A8 de CU-13). Devuelve cuántas canceló: 0 en A1.
    public static function verificarVencimientosDePermisos(Carbon $fecha_hora_actual): int
    {
        $canceladas = 0;

        foreach (self::buscarConfirmacionesVencidas($fecha_hora_actual) as $reserva) {
            try {
                if (! $reserva->ejecutarCancelacionPorVencimiento($fecha_hora_actual)) {
                    continue;
                }
            } catch (Throwable $excepcion) {
                Log::error('No se pudo cancelar por falta de confirmación de permisos la reserva '.$reserva->numero_reserva.': '
                    .$excepcion->getMessage());

                continue;
            }

            $canceladas++;
            self::notificarCliente(TipoNotificacion::CancelacionFaltaPermisos, $reserva->numero_reserva, true);
        }

        return $canceladas;
    }

    // Paso 2. La base trae las reservas de los dos estados y el vencimiento lo decide verificarFechaLimiteConfirmacion(),
    // la misma regla con la que CU-04 deja de admitir la validación: vencida es después del segundo guardado en
    // fecha_limite_confirmacion (las 23:59:59 del día límite, o las 72 horas de una «Sin Permiso» del último día).
    // Filtrar acá no pesa: son sólo las reservas que esperan la confirmación de los permisos.
    public static function buscarConfirmacionesVencidas(Carbon $fecha_hora_actual): Collection
    {
        return self::whereIn('estado', self::ESTADOS_SIN_CONFIRMAR)
            ->orderBy('id_reserva')
            ->get()
            ->filter(fn (self $reserva) => $reserva->tieneConfirmacionVencida($fecha_hora_actual))
            ->values();
    }

    // Pasos 3 y 4: el ejecutarCancelacionConReintegro() del DS-26. Se aparta del diagrama porque el de CU-17 recibe el
    // monto ya calculado y su calcularReintegro() sólo acepta «Sin Permiso» (A1 de CU-17), y CU-26 también cancela
    // «Pendiente». Para no aflojar ese control, este método tiene el suyo (tieneConfirmacionVencida()), calcula el 100 %
    // de lo abonado con sumarPagos() y se lo pasa al de CU-17, que registra la devolución con motivo «Reintegro»
    // (generarDevolucion) y pasa la reserva a «Cancelada» (setEstado). El cupo no se toca: una reserva cancelada deja de
    // contar en Excursion::sumarPlazasReservadas().
    // Se vuelve a leer la reserva con su fila bloqueada y se controla todo de nuevo adentro: si mientras tanto el cliente
    // pidió el reintegro o la reprogramación, el administrador validó los permisos u otra verificación ya la canceló, no
    // hace nada. Así, correr la tarea dos veces no registra dos devoluciones. Devuelve si la canceló.
    public function ejecutarCancelacionPorVencimiento(Carbon $fecha_hora_actual): bool
    {
        $cancelada = DB::transaction(function () use ($fecha_hora_actual) {
            $actual = self::lockForUpdate()->find($this->id_reserva);

            if ($actual === null || ! $actual->tieneConfirmacionVencida($fecha_hora_actual)) {
                return false;
            }

            $actual->ejecutarCancelacionConReintegro($actual->sumarPagos(), $fecha_hora_actual);

            return true;
        });

        $this->refresh();

        return $cancelada;
    }

    // La condición de CU-26 para una reserva ya leída.
    private function tieneConfirmacionVencida(Carbon $fecha_hora_actual): bool
    {
        return $this->validarEstado(self::ESTADOS_SIN_CONFIRMAR)
            && ! $this->verificarFechaLimiteConfirmacion($fecha_hora_actual);
    }

    /* -------------------------- CU-12 Reportar etapa -------------------------- */

    // A2 4.3. Sólo termina el viaje una reserva «Confirmada»: las demás no viajaron. Lo controla también acá, y no sólo
    // la excursión que elige las confirmadas, para que una reserva cancelada nunca pase a «Finalizada».
    public function finalizarReserva(): void
    {
        if (! $this->validarEstado([EstadoReserva::Confirmada])) {
            throw new DomainException('La reserva '.$this->numero_reserva.' está en «'.$this->estado->value.'»: sólo se finaliza una reserva «Confirmada».');
        }

        $this->estado = EstadoReserva::Finalizada;
        $this->save();
    }

    /* ----------------------------- CU-21 Cancelar ----------------------------- */

    public function calcularReembolso(Carbon $fecha_actual): float
    {
        if (! $this->validarEstado([EstadoReserva::Pendiente, EstadoReserva::Confirmada])) {
            throw new DomainException('La reserva se encuentra en estado «'.$this->estado->value.'» y no admite cancelación por esta vía.');
        }

        $fechaSalida = Carbon::parse($this->excursion->fecha_salida);

        return $this->calcularMontoReembolso($fecha_actual, $fechaSalida, $this->sumarPagos());
    }

    private function calcularMontoReembolso(Carbon $fecha_actual, Carbon $fecha_salida, float $monto_abonado): float
    {
        $diasAnticipacion = (int) $fecha_actual->copy()->startOfDay()
            ->diffInDays($fecha_salida->copy()->startOfDay());
        $diasLimite = config('reserva.dias_anticipacion_reembolso');
        $porcentaje = config('reserva.porcentaje_reembolso');

        if ($diasAnticipacion > $diasLimite && $fecha_salida->greaterThan($fecha_actual)) {
            return $monto_abonado * $porcentaje;
        }

        return 0.0;
    }

    // Lo que hace la pantalla al confirmar, con el monto que le mostró al cliente. El reembolso se calcula de nuevo sobre
    // la reserva leída con su fila bloqueada: entre que el cliente vio el monto y confirmó, la reserva pudo cambiar de
    // estado (por ejemplo, pasar a «Finalizada» con CU-12), recibir el pago del saldo (CU-16) o quedar a 30 días o menos
    // de la salida. Si ya no admite la cancelación, o si el monto ya no es el que vio el cliente, no se cancela ni se
    // registra la devolución: el cliente tiene que ver el monto nuevo y confirmar otra vez, así como CU-17 corta el
    // reintegro si el plazo vence en el medio. Ni la segunda lectura ni la comparación del monto están en el DS-21.
    public function confirmarCancelacion(float $monto_mostrado, Carbon $fecha_actual): void
    {
        DB::transaction(function () use ($monto_mostrado, $fecha_actual) {
            $actual = self::lockForUpdate()->findOrFail($this->id_reserva);

            $montoReembolso = $actual->calcularReembolso($fecha_actual);

            if (round($montoReembolso, 2) !== round($monto_mostrado, 2)) {
                throw new DomainException('El monto a reembolsar cambió mientras revisabas la cancelación. Revisalo y confirmá de nuevo.');
            }

            $actual->ejecutarCancelacionConReembolso($montoReembolso, $fecha_actual);
        });

        // Quien la llamó (la pantalla) ve la reserva como quedó.
        $this->refresh();
    }

    public function ejecutarCancelacionConReembolso(float $monto_reembolso, Carbon $fecha_actual): void
    {
        $this->cancelarRegistrandoDevolucion($monto_reembolso, $fecha_actual, MotivoDevolucion::Reembolso);
    }

    /* --------------------------- CU-17 Solicitar Reintegro -------------------- */

    // La regla del DS-17, la misma que admiteReprogramacion() de CU-19: se pide el reintegro sólo con la reserva «Sin
    // Permiso» y dentro de la fecha límite de confirmación; al vencer, la cancela CU-26. El plazo lo decide
    // verificarFechaLimiteConfirmacion(), así CU-17 y CU-19 cortan en el mismo segundo que CU-04 y CU-26: hasta las
    // 23:59:59 del día límite todavía se puede, y desde las 00:00:00 del día siguiente ya no (si pasó a «Sin Permiso» el
    // último día, el plazo es de 72 horas: fijarFechaLimiteSinPermiso()). También la usa getOpcionesHabilitadas() para
    // ofrecer la opción en Mi reserva.
    public function admiteReintegro(): bool
    {
        return $this->validarEstado([EstadoReserva::SinPermiso]) && $this->verificarFechaLimiteConfirmacion();
    }

    public function calcularReintegro(): float
    {
        $this->verificarAdmiteReintegro();

        return $this->sumarPagos();
    }

    // Lo que hace la pantalla al confirmar. La regla se controla de nuevo sobre la reserva leída con su fila bloqueada: el
    // plazo pudo vencer, o la reserva cambiar de estado, entre que el cliente vio el monto y confirmó. Si ya no admite el
    // reintegro, no se cancela ni se registra la devolución. No está en el DS-17 y el control no va dentro de
    // ejecutarCancelacionConReintegro() porque CU-26 también la usa, justo cuando el plazo ya venció. Devuelve el monto
    // reintegrado.
    public function confirmarReintegro(Carbon $fecha_actual): float
    {
        $montoReintegro = DB::transaction(function () use ($fecha_actual) {
            $actual = self::lockForUpdate()->findOrFail($this->id_reserva);

            $montoReintegro = $actual->calcularReintegro();
            $actual->ejecutarCancelacionConReintegro($montoReintegro, $fecha_actual);

            return $montoReintegro;
        });

        // Quien la llamó (la pantalla) ve la reserva como quedó.
        $this->refresh();

        return $montoReintegro;
    }

    public function ejecutarCancelacionConReintegro(float $monto_reintegro, Carbon $fecha_actual): void
    {
        $this->cancelarRegistrandoDevolucion($monto_reintegro, $fecha_actual, MotivoDevolucion::Reintegro);
    }

    // Si admiteReintegro() no se cumple, lanza DomainException con el aviso para el cliente, como
    // verificarAdmiteReprogramacion() de CU-19: uno si la reserva no está «Sin Permiso» (A1) y otro si venció el plazo.
    private function verificarAdmiteReintegro(): void
    {
        if ($this->admiteReintegro()) {
            return;
        }

        if (! $this->validarEstado([EstadoReserva::SinPermiso])) {
            throw new DomainException('La reserva se encuentra en estado «'.$this->estado->value.'» y no admite reintegro.');
        }

        throw new DomainException('El plazo para elegir entre el reintegro y la reprogramación venció el '
            .$this->fecha_limite_confirmacion->format('d/m/Y').': la reserva ya no admite reintegro.');
    }

    private function cancelarRegistrandoDevolucion(float $monto, Carbon $fecha, MotivoDevolucion $motivo): void
    {
        DB::transaction(function () use ($monto, $fecha, $motivo) {
            if ($monto > 0) {
                $this->devolucion()->create([
                    'fecha' => $fecha,
                    'monto' => $monto,
                    'motivo' => $motivo,
                ]);
            }

            $this->estado = EstadoReserva::Cancelada;
            $this->save();
        });
    }

    private function sumarPagos(): float
    {
        return (float) $this->pagos->sum('monto');
    }

    private function validarEstado(array $estados): bool
    {
        return in_array($this->estado, $estados, true);
    }

    /* ------------------- Filtros del listado (CU-07) ------------------- */

    public function scopeDeEstado(Builder $consulta, EstadoReserva $estado): Builder
    {
        return $consulta->where('estado', $estado->value);
    }

    public function scopeDePaquete(Builder $consulta, int $idPaquete): Builder
    {
        return $consulta->whereHas('excursion', fn (Builder $excursion) => $excursion->where('id_paquete', $idPaquete));
    }

    public function scopeDeGuia(Builder $consulta, int $idGuia): Builder
    {
        return $consulta->whereHas('excursion', fn (Builder $excursion) => $excursion->where('id_guia', $idGuia));
    }

    public function scopeConEquipoCamping(Builder $consulta): Builder
    {
        return $consulta->whereHas('excursionistas', fn (Builder $integrante) => $integrante->where('equipo_camping', true));
    }

    public function scopeConServicio(Builder $consulta, TipoServicio $tipo): Builder
    {
        return $consulta->whereHas('excursion.paquete.servicios', fn (Builder $servicio) => $servicio->where('tipo', $tipo->value));
    }

    /* ----------------------------- CU-20 Modificar ---------------------------- */

    public function iniciarModificacion(): Collection
    {
        if (! $this->validarEstado([EstadoReserva::Confirmada])) {
            throw new DomainException('La reserva no admite modificación.');
        }

        if (! $this->excursion->cumpleAnticipacionMinima(now())) {
            throw new DomainException('Ya no se puede modificar: falta poco para la salida.');
        }

        return Excursion::buscarOtrasDelPaquete(
            $this->excursion->id_paquete,
            $this->excursion->fecha_salida,
            $this->getCantidadExcursionistas()
        );
    }

    private function getCantidadExcursionistas(): int
    {
        return $this->excursionistas->count();
    }

    // Solo calcula: no asigna ni guarda. Lo usan fijarFechasLimite y los resúmenes de CU-19 y CU-20.
    public static function calcularFechaLimite($fechaSalida): Carbon
    {
        return Carbon::parse($fechaSalida)
            ->subMonthsNoOverflow(config('reserva.meses_anticipacion_fechas_limite'))
            ->endOfDay();
    }

    private function armarResumenCambio(Collection $habilitadas, Carbon $fechaElegida): array
    {
        $destino = $habilitadas->first(
            fn ($excursion) => $excursion->fecha_salida->isSameDay($fechaElegida)
        );

        if ($destino === null) {
            throw new DomainException('La excursión elegida ya no está disponible.');
        }

        $fechaLimite = self::calcularFechaLimite($destino->getFechaSalida());

        return [
            'origen' => $this->excursion->getDatosExcursion(),
            'destino' => $destino->getDatosExcursion(),
            'fecha_limite' => $fechaLimite,
            'fecha_limite_saldo' => $this->estado_saldo === EstadoSaldo::Adeudado ? $fechaLimite : null,
        ];
    }

    public function elegirExcursionDestino(Carbon $fechaElegida): array
    {
        return $this->armarResumenCambio($this->iniciarModificacion(), $fechaElegida);
    }

    private function cambiarExcursion(Excursion $destino): void
    {
        $this->excursion()->associate($destino);
    }

    // Todo se hace sobre $actual, la reserva leída con su fila bloqueada, y no sobre esta instancia, que la pantalla leyó
    // antes: si mientras tanto se registró el pago del saldo (Pago::registrarPagoSaldo(), que bloquea la misma fila) o se
    // canceló la reserva, $this tiene el estado viejo y fijarFechasLimite() usaría un estado_saldo que ya no es. Por eso
    // cada caso de uso le pasa su control ($verificarAdmite), que se hace sobre $actual y lanza DomainException si la
    // reserva ya no admite el cambio: así no se toca nada.
    //
    // Cruce con CU-16: R-16 sólo cobra el saldo con la reserva «Confirmada». Si el cliente modifica con un cobro abierto
    // en Mercado Pago, ese pago queda sin registrar y la devolución es manual, con la agencia (decisión del grupo del
    // 13/09). No se bloquea la modificación porque la base no tiene dónde guardar el cobro abierto.
    private function cambiarDeExcursionPendiente(Closure $verificarAdmite, Excursion $destino): void
    {
        DB::transaction(function () use ($destino, $verificarAdmite) {
            $actual = self::with('excursionistas')->lockForUpdate()->findOrFail($this->id_reserva);

            $verificarAdmite($actual);

            $destino = Excursion::lockForUpdate()->findOrFail($destino->id_excursion);

            if (! $destino->cumpleAnticipacionMinima(now())) {
                throw new DomainException('La excursión elegida ya no cumple la anticipación mínima.');
            }

            if (! $destino->tieneCupoPara($actual->getCantidadExcursionistas())) {
                throw new DomainException('La excursión elegida ya no tiene lugar.');
            }

            $actual->cambiarExcursion($destino);
            $actual->estado = EstadoReserva::Pendiente;

            foreach ($actual->excursionistas as $excursionista) {
                $excursionista->actualizarPermiso(EstadoPermiso::Pendiente);
            }

            $actual->fijarFechasLimite($destino->getFechaSalida());
            $actual->save();
        });

        // Quien la llamó (la pantalla) ve la reserva como quedó.
        $this->refresh();
    }

    public function modificarExcursion(Excursion $destino): void
    {
        $this->cambiarDeExcursionPendiente(function (self $actual) {
            if (! $actual->validarEstado([EstadoReserva::Confirmada])) {
                throw new DomainException('La reserva no admite modificación.');
            }
        }, $destino);
    }

    /* ----------------------------- CU-19 Solicitar Reprogramación Por Falta de Permisos ---------------------------- */

    // La regla del DS-19: se reprograma sólo una reserva «Sin Permiso» y dentro de la fecha límite de confirmación; al
    // vencer, la cancela CU-26. El plazo lo decide verificarFechaLimiteConfirmacion(), la misma que usan CU-04 para
    // validar y CU-26 para cancelar: hasta las 23:59:59 del día límite todavía se puede reprogramar, y desde las 00:00:00
    // del día siguiente ya no (si pasó a «Sin Permiso» el último día, el plazo es de 72 horas:
    // fijarFechaLimiteSinPermiso()). También la usa getOpcionesHabilitadas() para ofrecer la opción en Mi reserva.
    public function admiteReprogramacion(): bool
    {
        return $this->validarEstado([EstadoReserva::SinPermiso]) && $this->verificarFechaLimiteConfirmacion();
    }

    public function iniciarReprogramacion(): Collection
    {
        $this->verificarAdmiteReprogramacion();

        return Excursion::buscarOtrasDelPaquete(
            $this->excursion->id_paquete,
            $this->excursion->fecha_salida,
            $this->getCantidadExcursionistas()
        );
    }

    public function elegirDestino(Carbon $fechaElegida): array
    {
        return $this->armarResumenCambio($this->iniciarReprogramacion(), $fechaElegida);
    }

    // La regla se controla de nuevo al confirmar, sobre la reserva bloqueada: el plazo pudo vencer entre que el cliente
    // eligió el destino y confirmó. Si venció, no se cambia nada (ni plazas, ni estado, ni permisos, ni fechas).
    public function reprogramar(Excursion $destino): void
    {
        $this->cambiarDeExcursionPendiente(fn (self $actual) => $actual->verificarAdmiteReprogramacion(), $destino);
    }

    // Si admiteReprogramacion() no se cumple, lanza DomainException con el aviso para el cliente, como
    // verificarAdmiteValidacionPermisos() de CU-04: uno si la reserva no está «Sin Permiso» y otro si venció el plazo.
    private function verificarAdmiteReprogramacion(): void
    {
        if ($this->admiteReprogramacion()) {
            return;
        }

        if (! $this->validarEstado([EstadoReserva::SinPermiso])) {
            throw new DomainException('La reserva no admite reprogramación.');
        }

        throw new DomainException('El plazo para elegir entre el reintegro y la reprogramación venció el '
            .$this->fecha_limite_confirmacion->format('d/m/Y').': la reserva ya no admite reprogramación.');
    }

    /* ------------------------- CU-13 Notificar cliente ------------------------ */

    // Paso 1: lo invoca otro caso de uso, después de guardar su cambio de estado y fuera de su transacción. Devuelve si
    // el correo quedó en la cola; sale de ahí al servicio de correo, que lo reintenta si falla. Nunca lanza una
    // excepción (A8): si algo falla al buscar la reserva, juntar los datos o encolar el correo, se anota en el registro
    // y el caso de uso que invocó sigue, con su cambio de estado ya guardado.
    //
    // $canceladaPorVencimiento sólo cuenta para «Cancelación por Falta de Permisos»: CU-26 la cancela al vencer la
    // fecha límite de confirmación (true) y CU-17, a pedido del cliente (false). No está en el DS-13: en la base las dos
    // cancelaciones quedan iguales, y la causa sólo la conoce quien cancela.
    public static function notificarCliente(TipoNotificacion $tipoNotificacion, string $numeroReserva, bool $canceladaPorVencimiento = false): bool
    {
        try {
            $reserva = self::buscarReserva($numeroReserva);

            if ($reserva === null) {
                Log::warning('No se envió el correo «'.$tipoNotificacion->value.'»: no existe la reserva '.$numeroReserva.'.');

                return false;
            }

            $reserva->notificar($tipoNotificacion, $canceladaPorVencimiento);

            return true;
        } catch (Throwable $excepcion) {
            Log::error('No se pudo enviar el correo «'.$tipoNotificacion->value.'» de la reserva '.$numeroReserva.': '
                .$excepcion->getMessage());

            return false;
        }
    }

    // Pasos 2 a 4: recupera los datos que dice el DS-13, arma el mensaje y lo envía. Todo lo que se calcula (montos,
    // saldo, integrantes sin permiso, porcentajes) se calcula acá y llega hecho al correo: la vista sólo lo muestra.
    public function notificar(TipoNotificacion $tipoNotificacion, bool $canceladaPorVencimiento = false): void
    {
        $paquete = $this->excursion->paquete;
        $montoTotal = round($this->calcularMontoTotal(), 2);
        $montoAbonado = round($this->sumarPagos(), 2);

        $datos = [
            'reserva' => $this->getDatos(),
            'excursion' => [
                'paquete' => $paquete->nombre,
                'fecha_salida' => $this->excursion->getFechaSalida(),
            ],
            // Del integrante, sólo lo que dice el DS-13: el documento no va en el correo.
            'excursionistas' => $this->getExcursionistas()
                ->map(fn (Excursionista $excursionista) => Arr::only($excursionista->getDatos(), ['nombre', 'apellido', 'estado_permiso']))
                ->all(),
            'pagos' => $this->pagos
                ->sortBy('id_pago')
                ->map(fn (Pago $pago) => $pago->getPago())
                ->values()
                ->all(),
            'noches_extra' => $this->noches_extra_antes + $this->noches_extra_despues,
            'monto_total' => $montoTotal,
            'monto_abonado' => $montoAbonado,
            'saldo_por_pagar' => $this->getSaldoPorPagar(round($montoTotal - $montoAbonado, 2)),
            // Sólo mientras los permisos se pueden validar.
            'fecha_limite_permisos' => $this->estado === EstadoReserva::Pendiente ? $this->fecha_limite_confirmacion : null,
            // La fecha que informa el correo (por ejemplo, la de la cancelación) es la de este envío. Se toma ahora y no
            // cuando sale de la cola: un reintento informa la misma.
            'fecha_envio' => now(),
        ];

        if (in_array($tipoNotificacion, [TipoNotificacion::PrimeraCompra, TipoNotificacion::PagoSaldo], true)) {
            $ultimoPago = $this->getUltimoPago();
            $datos['ultimo_pago'] = $ultimoPago->getPago();
            $datos['comprobante'] = $ultimoPago->getComprobante()?->getDatos();
        }

        if ($tipoNotificacion === TipoNotificacion::PrimeraCompra) {
            $datos['paquete'] = $paquete->getDatosComprobante();
        }

        if ($tipoNotificacion === TipoNotificacion::CancelacionReserva) {
            $datos['devolucion'] = $this->devolucion?->getDatos();
            $datos['dias_anticipacion_reembolso'] = config('reserva.dias_anticipacion_reembolso');
            $datos['porcentaje_reembolso'] = (int) round(config('reserva.porcentaje_reembolso') * 100);
        }

        if ($tipoNotificacion === TipoNotificacion::PermisosRechazados) {
            $datos['sin_permiso'] = $this->getExcursionistas()
                ->filter(fn (Excursionista $excursionista) => $excursionista->estado_permiso === EstadoPermiso::NoObtenido)
                ->map(fn (Excursionista $excursionista) => $excursionista->nombre.' '.$excursionista->apellido)
                ->values()
                ->all();
            // Lo que le devolvería CU-17 si elige el reintegro: todo lo abonado. No se llama a calcularReintegro(), que
            // además controla el estado y el plazo: el correo sólo informa el monto.
            $datos['monto_reintegro'] = $montoAbonado;
        }

        if ($tipoNotificacion === TipoNotificacion::CancelacionFaltaPermisos) {
            $datos['devolucion'] = $this->devolucion?->getDatos();
            // Con CU-26 el correo da una sola causa, como dice el A7: venció el plazo de confirmación sin que la reserva
            // quedara confirmada. Vale igual para una «Pendiente» sin validar que para una «Sin Permiso» que no eligió.
            $datos['cancelada_por_vencimiento'] = $canceladaPorVencimiento;
        }

        $this->enviarCorreo($this->correo_electronico, $this->armarMensaje($tipoNotificacion, $datos));
    }

    public function getDatos(): array
    {
        return [
            'numero_reserva' => $this->numero_reserva,
            'correo_electronico' => $this->correo_electronico,
            'estado' => $this->estado,
            'estado_saldo' => $this->estado_saldo,
            'noches_extra_antes' => $this->noches_extra_antes,
            'noches_extra_despues' => $this->noches_extra_despues,
            'fecha_limite_confirmacion' => $this->fecha_limite_confirmacion,
            'fecha_limite_saldo' => $this->fecha_limite_saldo,
        ];
    }

    // Paso 3: el texto de cada tipo está en la vista del correo; acá se elige el tipo y se le pasan los datos.
    public function armarMensaje(TipoNotificacion $tipoNotificacion, array $datos): NotificacionCliente
    {
        return new NotificacionCliente($tipoNotificacion, $datos);
    }

    // Paso 4. El correo va a la cola, que lo reintenta si el servicio de correo falla (NotificacionCliente::INTENTOS).
    // Sólo al titular de la reserva, nunca a los excursionistas. Si no se puede encolar, notificarCliente() lo anota (A8).
    public function enviarCorreo(string $correoElectronico, NotificacionCliente $mensaje): void
    {
        Mail::to($correoElectronico)->queue($mensaje);
    }

    // El último pago es el de mayor id_pago y no el de fecha más reciente: pago.fecha guarda sólo el día, y la seña y el
    // saldo pueden pagarse el mismo día.
    private function getUltimoPago(): Pago
    {
        return $this->pagos->sortByDesc('id_pago')->first();
    }

    // El saldo que el cliente todavía puede pagar, con su fecha límite: sólo con la reserva «Pendiente» o «Confirmada» y
    // el saldo «Adeudado». En «Sin Permiso», «Cancelada» o «Finalizada» ya no se paga, y el correo no lo pide.
    private function getSaldoPorPagar(float $saldo): ?array
    {
        if (! $this->validarEstado([EstadoReserva::Pendiente, EstadoReserva::Confirmada])
            || $this->estado_saldo !== EstadoSaldo::Adeudado
            || $saldo <= 0) {
            return null;
        }

        return [
            'monto' => $saldo,
            'fecha_limite' => $this->fecha_limite_saldo,
        ];
    }
}
