<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/v1/bench-traffic', function () {
    return response()->json([
        'status' => 'success',
        'data' => 'Payload delivered within rate limits.',
    ]);
})->middleware('traffic.limit:20,1,1,open');