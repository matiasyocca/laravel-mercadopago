<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MatiasYocca\MercadoPago\Http\Controllers\WebhookController;

Route::prefix((string) config('mercadopago.routes.prefix', 'mercadopago'))
    ->middleware((array) config('mercadopago.routes.middleware', ['api']))
    ->group(function (): void {
        Route::post('webhook', WebhookController::class)->name('mercadopago.webhook');
    });
