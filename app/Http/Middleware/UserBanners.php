<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Filament\User\Widgets\UserBanners as UserBannersWidget;
use Closure;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Symfony\Component\HttpFoundation\Response;

final class UserBanners
{
    private const string CHECKOUT_SUCCESS_ROUTE = 'filament.user.pages.checkout.success';

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

        if ($request->routeIs(self::CHECKOUT_SUCCESS_ROUTE)) {
            return $next($request);
        }

        FilamentView::registerRenderHook(
            PanelsRenderHook::CONTENT_START,
            fn (): string => Blade::render('@livewire('.UserBannersWidget::class.'::class)'),
        );

        return $next($request);
    }
}
