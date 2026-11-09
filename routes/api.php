<?php

use App\Node\A\MetricasController;
use App\Node\A\ReportesController;
use App\Node\A\SaludController;
use Illuminate\Support\Facades\Route;

Route::middleware(['solo_nodo_a', 'fencing'])->group(function () {
    Route::post('/reportes', [ReportesController::class, 'almacenar']);
    Route::get('/salud', SaludController::class);
    Route::get('/metricas', MetricasController::class);
});
