<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/sard/eventos', function () {
    return response()->json(['ok' => true]);
})->middleware('fencing');
