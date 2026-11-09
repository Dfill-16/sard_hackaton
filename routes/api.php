<?php

use App\Channel\CanalController;
use App\Http\Controllers\Tablero\DecisionController;
use App\Http\Controllers\Tablero\EstadoController;
use App\Http\Controllers\Tablero\IncidenteController;
use App\Node\A\AcksController;
use App\Node\A\LiderController;
use App\Node\A\MetricasController;
use App\Node\A\ReportesController;
use App\Node\A\SaludController;
use App\Node\B\ControlController;
use App\Node\B\EntregaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Todos los nodos: la respuesta depende del rol (contratos/api.md)
|--------------------------------------------------------------------------
*/
Route::get('/salud', SaludController::class);

/*
|--------------------------------------------------------------------------
| Nodo A :8001
|--------------------------------------------------------------------------
*/
Route::middleware(['solo_nodo_a', 'fencing'])->group(function () {
    Route::post('/reportes', [ReportesController::class, 'almacenar']);
    Route::post('/acks', AcksController::class);
    Route::post('/lider', LiderController::class);
    Route::get('/metricas', MetricasController::class);
});

/*
|--------------------------------------------------------------------------
| CANAL :8010
|--------------------------------------------------------------------------
*/
Route::middleware('rol:CANAL')->prefix('canal')->group(function () {
    Route::post('/enviar', [CanalController::class, 'enviar']);
    Route::get('/estadisticas', [CanalController::class, 'estadisticas']);
    Route::post('/config', [CanalController::class, 'configurar']);
});

/*
|--------------------------------------------------------------------------
| Nodos B y C :8002 / :8003 (mismo código)
|--------------------------------------------------------------------------
*/
Route::middleware(['rol:B,C', 'fencing'])->group(function () {
    Route::post('/entrega/reporte', [EntregaController::class, 'reporte']);
    Route::get('/tablero/estado', EstadoController::class);
    Route::get('/incidentes/{uuid}', IncidenteController::class);
    Route::post('/decisiones', DecisionController::class);

    Route::post('/latido', [ControlController::class, 'latido']);
    Route::get('/lider', [ControlController::class, 'lider']);
    Route::get('/eventos', [ControlController::class, 'eventos']);
    Route::post('/eventos/lote', [ControlController::class, 'lote']);
    Route::post('/reportes/faltantes', [ControlController::class, 'faltantes']);
    Route::post('/demo/aislar', [ControlController::class, 'aislar']);
});
