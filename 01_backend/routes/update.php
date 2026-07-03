<?php

use App\Http\Controllers\UpdateController;
use Illuminate\Support\Facades\Route;

Route::get('/', [UpdateController::class, 'updateSoftwareIndex'])->name('index');
Route::post('update-system', [UpdateController::class, 'updateSoftware'])->name('update-system');

Route::fallback(function () {
    return redirect('/');
});
