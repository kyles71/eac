<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\StripeServiceContract;
use App\Models\Costume;
use App\Models\Course;
use App\Models\CourseForm;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\Form;
use App\Models\GiftCardType;
use App\Models\Holiday;
use App\Models\Student;
use App\Observers\CourseFormObserver;
use App\Observers\EnrollmentObserver;
use App\Observers\EventObserver;
use App\Observers\FormObserver;
use App\Observers\HolidayObserver;
use App\Observers\ProductableObserver;
use App\Observers\StudentObserver;
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
        $this->configureDatabaseDumpOptions();

        $this->app->singleton(StripeServiceContract::class, function (): StripeService {
            return new StripeService(
                new StripeClient(config('services.stripe.secret')),
            );
        });
    }

    public function boot(): void
    {
        Course::observe(ProductableObserver::class);
        CourseForm::observe(CourseFormObserver::class);
        Enrollment::observe(EnrollmentObserver::class);
        Event::observe(EventObserver::class);
        Form::observe(FormObserver::class);
        GiftCardType::observe(ProductableObserver::class);
        Holiday::observe(HolidayObserver::class);
        Student::observe(StudentObserver::class);
        Costume::observe(ProductableObserver::class);

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
