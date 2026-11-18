<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\DownloadGenerationController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/studio');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::get('/register', [AuthController::class, 'show'])->name('register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
});
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/studio', [GenerationController::class, 'index'])->name('studio');
    Route::get('/generations/{generation}/download', DownloadGenerationController::class)
        ->name('generations.download')->middleware('throttle:20,1');
    Route::post('/generations/quote', [GenerationController::class, 'quote'])->middleware('throttle:60,1');
    Route::post('/generations', [GenerationController::class, 'store'])->name('generations.store')->middleware('throttle:10,1');
    Route::post('/credits', CreditController::class)->middleware('throttle:10,1');
    Route::get('/admin/reports', ReportController::class)->middleware(['can:viewReports', 'throttle:10,1']);
});
