<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\StripeServiceContract;
use App\Services\StripeService;
use BezhanSalleh\PanelSwitch\PanelSwitch;
use Illuminate\Support\ServiceProvider;
use Stripe\StripeClient;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StripeServiceContract::class, function (): StripeService {
            return new StripeService(
                new StripeClient(config('services.stripe.secret')),
            );
        });
    }

    public function boot(): void
    {
        PanelSwitch::configureUsing(function (PanelSwitch $panelSwitch) {
            $panelSwitch->simple();
        });
    }
}
