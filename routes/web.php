<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\MessageBag;

Route::get('/', function () {
    return view('inicio');
});
// La fecha de salida va como 2027-01-18. El paquete tiene que ser un número (hasta 18 cifras, lo que entra en un
// BIGINT): si no, la ruta da 404 en vez de un error. La fecha la valida el componente, que muestra un aviso.
Route::livewire('/reservar/{idPaquete}/{fechaSalida}', 'realizar-reserva')->where('idPaquete', '[0-9]{1,18}');
Route::livewire('/reservar/pago', 'pagar-reserva');
// Dirección de vuelta de Mercado Pago (CU-15). block(): si la vuelta llega dos veces seguidas (F5 o doble clic), la
// segunda espera a que termine la primera. Laravel lee la sesión al empezar el pedido y la guarda entera al terminar:
// sin esperar, la segunda no vería la reserva recién registrada y pisaría la sesión con la vieja.
Route::livewire('/reservar/confirmada', 'reserva-confirmada')->block();
Route::livewire('/mi-reserva', 'consultar-reserva');
Route::livewire('/mi-reserva/{numeroReserva}/cancelar', 'cancelar-reserva');
Route::livewire('/mi-reserva/{numeroReserva}/reintegro', 'solicitar-reintegro');

// Muestrario de los componentes del portal, sólo para desarrollo.
if (app()->isLocal()) {
    Route::get('/guia-visual', function () {
        // Carga un error de ejemplo para mostrar el campo en ese estado.
        view()->shared('errors')->put('default', new MessageBag([
            'ejemplo-error' => 'El correo no tiene un formato válido.',
        ]));

        return view('guia-visual');
    });
}
