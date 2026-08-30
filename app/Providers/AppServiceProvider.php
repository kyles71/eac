<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\StripeServiceContract;
use App\Filament\Shared\Forms\Components\PreviewBuilder;
use App\Listeners\Forms\ReconcileRequiredFormsForPublishedVersion;
use App\Models\Course;
use App\Models\CourseForm;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\Gear;
use App\Models\GiftCardType;
use App\Models\Holiday;
use App\Models\Student;
use App\Models\User;
use App\Observers\CourseFormObserver;
use App\Observers\EnrollmentObserver;
use App\Observers\EventObserver;
use App\Observers\HolidayObserver;
use App\Observers\ProductableObserver;
use App\Observers\StudentObserver;
use App\Observers\UserObserver;
use App\Services\StripeService;
use App\Support\PasswordRequirements;
use App\Support\TextmagicMailTransportFactory;
use BezhanSalleh\PanelSwitch\PanelSwitch;
use Filament\Forms\Components\Builder;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Kyle\FilamentFormBuilder\Events\FormVersionActivated;
use Stripe\StripeClient;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Builder::class, PreviewBuilder::class);

        $this->configureDatabaseDumpOptions();

        $this->app->singleton(StripeServiceContract::class, function (): StripeService {
            return new StripeService(
                new StripeClient(config('services.stripe.secret')),
            );
        });
    }

    public function boot(): void
    {
        Password::defaults(fn (): Password => PasswordRequirements::rule());

        Course::observe(ProductableObserver::class);
        CourseForm::observe(CourseFormObserver::class);
        Enrollment::observe(EnrollmentObserver::class);
        Event::observe(EventObserver::class);
        GiftCardType::observe(ProductableObserver::class);
        Holiday::observe(HolidayObserver::class);
        Student::observe(StudentObserver::class);
        Gear::observe(ProductableObserver::class);
        User::observe(UserObserver::class);
        EventFacade::listen(FormVersionActivated::class, ReconcileRequiredFormsForPublishedVersion::class);

        Mail::extend('textmagic', fn (array $config) => TextmagicMailTransportFactory::make($config));

        PanelSwitch::configureUsing(function (PanelSwitch $panelSwitch) {
            $panelSwitch->simple();
        });
    }

    private function configureDatabaseDumpOptions(): void
    {
        foreach (['mysql', 'mariadb'] as $connection) {
            $dumpConfiguration = (array) config("database.connections.{$connection}.dump", []);

            config()->set("database.connections.{$connection}.dump", [
                ...$dumpConfiguration,
                'use_single_transaction' => true,
                'use_quick' => true,
                'timeout' => 600,
            ]);
        }
    }
}
