<?php

use App\Http\Controllers\DemandeController;
use Illuminate\Support\Facades\Route;

Route::post('/demandes', [DemandeController::class, 'deposer']);
Route::get('/demandes/{id}', [DemandeController::class, 'consulter'])->whereNumber('id');
Route::post('/demandes/{id}/traiter', [DemandeController::class, 'traiter'])->whereNumber('id');
Route::post('/demandes/{id}/valider', [DemandeController::class, 'valider'])->whereNumber('id');
Route::post('/demandes/{id}/rejeter', [DemandeController::class, 'rejeter'])->whereNumber('id');

Route::get('/usagers/{npi}/demandes', [DemandeController::class, 'lister']);
Route::get('/usagers/{npi}/statistiques', [DemandeController::class, 'statistiques']);
