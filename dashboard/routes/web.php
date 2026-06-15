<?php

use App\Http\Controllers\AsignacionController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('asignaciones.index'));

Route::get('/asignaciones', [AsignacionController::class, 'index'])->name('asignaciones.index');
Route::get('/asignaciones/crear', [AsignacionController::class, 'create'])->name('asignaciones.create');
Route::post('/asignaciones', [AsignacionController::class, 'store'])->name('asignaciones.store');
Route::get('/asignaciones/{asignacion}', [AsignacionController::class, 'show'])->name('asignaciones.show');
