<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\StripeServiceContract;
use App\Models\Costume;
use App\Models\Course;
use App\Models\GiftCardType;
use App\Observers\ProductableObserver;
use App\Services\StripeService;
use App\Support\TextmagicMailTransportFactory;
use BezhanSalleh\PanelSwitch\PanelSwitch;
use Illuminate\Support\Facades\Mail;
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
        Course::observe(ProductableObserver::class);
        GiftCardType::observe(ProductableObserver::class);
        Costume::observe(ProductableObserver::class);

        Mail::extend('textmagic', fn (array $config) => TextmagicMailTransportFactory::make($config));

        PanelSwitch::configureUsing(function (PanelSwitch $panelSwitch) {
            $panelSwitch->simple();
        });
    }
}
