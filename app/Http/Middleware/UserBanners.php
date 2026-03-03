<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Filament\User\Pages\MyEnrollments;
use App\Filament\User\Resources\FormUsers\Pages\ListFormUsers;
use App\Models\Student;
use Closure;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Symfony\Component\HttpFoundation\Response;

final class UserBanners
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return $next($request);
        }

        if ($enrollment_count = Auth::user()->enrollments()->open()->count()) {
            $this->showEnrollmentBanner($enrollment_count);
        }

        $student_waivers_needed = Student::query()
            ->whereHas('forms', function ($query) {
                $query->formIsActive()
                    ->where('form_users.user_id', Auth::id())
                    ->whereNull('form_users.signature')
                    ->whereNull('form_users.date_signed');
            })
            ->get();

        if ($student_waivers_needed->isNotEmpty()) {
            $this->showWaiverBanner($student_waivers_needed);
        }

        return $next($request);
    }

    private function showEnrollmentBanner(int $enrollment_count): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::CONTENT_START,
            fn (): string => Blade::render(
                'filament.banners.enrollment-banner',
                [
                    'enrollmentCount' => $enrollment_count,
                    'enrollmentsUrl' => MyEnrollments::getUrl(),
                ],
            ),
        );
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Student>  $student_waivers_needed
     */
    private function showWaiverBanner($student_waivers_needed): void
    {
        $names = $student_waivers_needed
            ->pluck('first_name')
            ->join(', ', ' and ');

        FilamentView::registerRenderHook(
            PanelsRenderHook::CONTENT_START,
            fn (): string => Blade::render(
                'filament.banners.waiver-banner',
                [
                    'names' => $names,
                    'waiversUrl' => ListFormUsers::getUrl(),
                ],
            ),
        );
    }
}
