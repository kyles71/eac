<?php

namespace App\Http\Middleware;

use App\Models\Form;
use App\Models\Student;
use Closure;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Symfony\Component\HttpFoundation\Response;

class UserBanners
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!Auth::check()) {
            return $next($request);
        }

        if ($enrollment_count = Auth::user()->enrollments()->open()->count()) {
            $this->showEnrollmentBanner($enrollment_count);
        }

        $student_waivers_needed = Form::query()
            ->with('formUsers.student')
            ->isActive()
            ->join('form_users', 'forms.id', '=', 'form_users.form_id')
            ->where('form_users.user_id', Auth::id())
            ->whereNotNull('form_users.student_id')
            ->whereNull('form_users.signature')
            ->whereNull('form_users.date_signed')
            ->groupBy('form_users.student_id')
            ->get();

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

    protected function showEnrollmentBanner(int $enrollment_count): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::CONTENT_START,
             fn (): string => Blade::render("@livewire('schema-wrapper', [
                'classes' => 'mt-2',
                'components' => [
                    Filament\Schemas\Components\Callout::make('Complete Enrollments')
                        ->description('You have $enrollment_count enrollment(s) that need to be assigned to a student.')
                        ->warning()
                        ->actions([
                            Filament\Actions\Action::make('view_enrollments')
                                ->label('Go to Enrollments')
                                ->url(App\Filament\User\Pages\MyEnrollments::getUrl()),
                        ])
                ]
            ])")
        );
    }

    protected function showWaiverBanner($student_waivers_needed): void
    {
        $waiver_count = $student_waivers_needed->count();
        $names = $student_waivers_needed->reduce(function (string $carry, Student $student, int $key) use ($waiver_count) {
            $carry .= $student->first_name;

            if ($key < $waiver_count - 1) {
                $carry .= ', ';
            } elseif ($key && $key === $waiver_count - 1) {
                $carry .= ' and ';
            }

            return $carry;
        }, '');

        FilamentView::registerRenderHook(
            PanelsRenderHook::CONTENT_START,
             fn (): string => Blade::render("@livewire('schema-wrapper', [
                'classes' => 'mt-2',
                'components' => [
                    Filament\Schemas\Components\Callout::make('Waivers Needed')
                        ->description('The following students need waivers signed: $names')
                        ->warning()
                        ->actions([
                            Filament\Actions\Action::make('view_forms')
                                ->label('Go to Waivers')
                                ->url(App\Filament\User\Resources\FormUsers\Pages\ListFormUsers::getUrl()),
                        ])
                ]
            ])")
        );
    }
}
