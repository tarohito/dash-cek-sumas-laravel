<?php

use App\Http\Controllers\QrVerificationController;
use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

Route::get('/q/{tagNumber}', [QrVerificationController::class, 'show'])
    ->middleware('throttle:public-scan');

Route::get('/{any?}', [SpaController::class, 'index'])
    ->where('any', '^(?!api(?:/|$)|q(?:/|$)|up$).*$');
