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
