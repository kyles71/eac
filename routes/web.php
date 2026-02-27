<?php

declare(strict_types=1);

use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::post('/stripe/webhook', StripeWebhookController::class)
    ->name('stripe.webhook');
