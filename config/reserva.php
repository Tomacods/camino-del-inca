<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Anticipación mínima para reservar
    |--------------------------------------------------------------------------
    |
    | Una excursión admite reservas sólo si sale, como pronto, esta cantidad de
    | meses después de la fecha en que se reserva (CU-14 Realizar Reserva).
    |
    */

    'meses_anticipacion_minima' => 3,

    /*
    |--------------------------------------------------------------------------
    | Máximo de noches extra en Cusco
    |--------------------------------------------------------------------------
    |
    | Las noches extra antes y después del recorrido, sumadas, no pueden pasar de
    | esta cantidad. Es un límite por reserva, para todo el grupo (CU-14
    | Realizar Reserva).
    |
    */

    'maximo_noches_extra' => 2,

    /*
    |--------------------------------------------------------------------------
    | Plazo de la retención del cupo
    |--------------------------------------------------------------------------
    |
    | Minutos que se guardan los lugares cuando el cliente confirma, para que
    | complete el pago. Si en ese plazo no paga, las plazas se liberan solas
    | (CU-14 Realizar Reserva). Se puede achicar con RESERVA_MINUTOS_RETENCION
    | en el .env para probar el vencimiento sin esperar.
    |
    */

    'minutos_retencion' => (int) env('RESERVA_MINUTOS_RETENCION', 5),

    /*
    |--------------------------------------------------------------------------
    | Porcentaje de la seña
    |--------------------------------------------------------------------------
    |
    | La parte del monto total que se paga si el cliente elige abonar la seña,
    | como fracción: 0.50 es el 50 % (CU-15 Pagar Reserva).
    |
    */

    'porcentaje_sena' => 0.50,

    /*
    |--------------------------------------------------------------------------
    | Anticipación para el reembolso
    |--------------------------------------------------------------------------
    |
    | Si el cliente cancela con más de esta cantidad de días antes de la salida,
    | se le devuelve una parte de lo abonado; si no, no se le devuelve nada
    | (CU-21 Cancelar Reserva).
    |
    */

    'dias_anticipacion_reembolso' => 30,

    /*
    |--------------------------------------------------------------------------
    | Porcentaje del reembolso
    |--------------------------------------------------------------------------
    |
    | La parte de lo abonado que se devuelve cuando la cancelación entra en el
    | plazo de reembolso, como fracción: 0.50 es el 50 % (CU-21 Cancelar Reserva).
    |
    */

    'porcentaje_reembolso' => 0.50,

];
