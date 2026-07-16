<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return response()->json([
        'application' => config('app.name'),
        'status'      => 'ok',
        'docs'        => url('/api'),
    ]);
});
