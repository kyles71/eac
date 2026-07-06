<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\StripeServiceContract;
use App\Events\Forms\FormVersionPublished;
use App\Listeners\Forms\ReconcileRequiredFormsForPublishedVersion;
use App\Models\Costume;
use App\Models\Course;
use App\Models\CourseForm;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\GiftCardType;
use App\Models\Holiday;
use App\Models\Student;
use App\Observers\CourseFormObserver;
use App\Observers\EnrollmentObserver;
use App\Observers\EventObserver;
use App\Observers\HolidayObserver;
use App\Observers\ProductableObserver;
use App\Observers\StudentObserver;
use App\Services\StripeService;
use App\Support\TextmagicMailTransportFactory;
use BezhanSalleh\PanelSwitch\PanelSwitch;
use Illuminate\Support\Facades\Event as EventFacade;
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
        CourseForm::observe(CourseFormObserver::class);
        Enrollment::observe(EnrollmentObserver::class);
        Event::observe(EventObserver::class);
        GiftCardType::observe(ProductableObserver::class);
        Holiday::observe(HolidayObserver::class);
        Student::observe(StudentObserver::class);
        Costume::observe(ProductableObserver::class);
        EventFacade::listen(FormVersionPublished::class, ReconcileRequiredFormsForPublishedVersion::class);

        Mail::extend('textmagic', fn (array $config) => TextmagicMailTransportFactory::make($config));

        PanelSwitch::configureUsing(function (PanelSwitch $panelSwitch) {
            $panelSwitch->simple();
        });
    }
}
