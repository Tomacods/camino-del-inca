<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
Route::livewire('/mi-reserva', 'consultar-reserva');
Route::livewire('/mi-reserva/{numeroReserva}/cancelar', 'cancelar-reserva');