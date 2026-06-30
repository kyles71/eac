<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\UserBannerRenderHookRegistrarService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class ManagedBanners
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            app(UserBannerRenderHookRegistrarService::class)->registerManagedBanners();
        }

        return $next($request);
    }
}
