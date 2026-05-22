<?php

declare(strict_types=1);

use App\Http\Controllers\StripeWebhookController;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect(Filament::getPanel('user')->getUrl()));

Route::post('/stripe/webhook', StripeWebhookController::class)
    ->name('stripe.webhook');
