<?php

use App\Http\Controllers\Api\V1\ClientInformationController;
use App\Http\Middleware\AuthenticateClientApi;
use Illuminate\Support\Facades\Route;

// Server-to-server only (no cookies, no CSRF): the caller's credential is checked first, then the limits apply per credential.
Route::prefix('v1/client-information')->middleware(['throttle:client-api-ip', AuthenticateClientApi::class])->group(function () {
    Route::post('request', [ClientInformationController::class, 'request'])->middleware('throttle:client-api-request')->name('api.client-information.request');
    Route::post('verify', [ClientInformationController::class, 'verify'])->middleware('throttle:client-api-verify')->name('api.client-information.verify');
});
