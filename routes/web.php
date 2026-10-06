<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\MessageBag;

Route::get('/', function () {
    return view('inicio');
});
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
