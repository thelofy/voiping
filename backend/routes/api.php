<?php

use App\Http\Controllers\Internal\AsteriskEventController;
use App\Http\Middleware\EnsureAsteriskIngestToken;
use Illuminate\Support\Facades\Route;

Route::post('/internal/v1/asterisk/events', [AsteriskEventController::class, 'store'])
    ->middleware(['auth:sanctum', EnsureAsteriskIngestToken::class, 'throttle:asterisk-ingest']);
