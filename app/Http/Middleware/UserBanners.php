<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\FormTypes;
use App\Filament\User\Pages\MyEnrollments;
use App\Filament\User\Resources\FormUsers\Pages\ListFormUsers;
use App\Models\FormUser;
use Closure;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

        $pendingForms = FormUser::query()
            ->with(['form', 'student'])
            ->where('user_id', Auth::id())
            ->pending()
            ->whereHas('form', fn ($query) => $query->isActive())
            ->get();

        $this->showFormBanners($pendingForms);

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

    /** @param Collection<int, FormUser> $pendingForms */
    private function showFormBanners(Collection $pendingForms): void
    {
        foreach (FormTypes::cases() as $formType) {
            $bannerView = $formType->getBannerView();

            if ($bannerView === null) {
                continue;
            }

            $assignments = $pendingForms
                ->filter(fn (FormUser $formUser): bool => $formUser->form?->form_type === $formType)
                ->values();

            if ($assignments->isEmpty()) {
                continue;
            }

            FilamentView::registerRenderHook(
                PanelsRenderHook::CONTENT_START,
                fn (): string => Blade::render($bannerView, [
                    'assignments' => $assignments,
                    'formsUrl' => ListFormUsers::getUrl(),
                ]),
            );
        }

        $genericForms = $pendingForms
            ->reject(fn (FormUser $formUser): bool => $formUser->form?->form_type->getBannerView() !== null);

        if ($genericForms->isEmpty()) {
            return;
        }

        FilamentView::registerRenderHook(
            PanelsRenderHook::CONTENT_START,
            fn (): string => Blade::render('filament.banners.forms-banner', [
                'formCount' => $genericForms->count(),
                'formsUrl' => ListFormUsers::getUrl(),
            ]),
        );
    }
}
