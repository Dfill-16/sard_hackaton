<?php

use App\Http\Controllers\Tablero\TableroController;
use App\Node\A\PwaController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/sard/eventos', function () {
    return response()->json(['ok' => true]);
})->middleware('fencing');

Route::get('/tablero', TableroController::class)->middleware('rol:B,C')->name('tablero');

Route::get('/pwa', PwaController::class)->middleware('solo_nodo_a')->name('pwa');
