<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class SecurityAuditLogger
{
    private const string REQUEST_ID_ATTRIBUTE = 'security_audit_request_id';

    public function authenticationFailed(Failed $event): void
    {
        $user = $event->user instanceof User ? $event->user : null;

        Log::warning('Authentication failed.', [
            'security_event' => 'auth.login_failed',
            'failure_reason' => $this->failureReason($event, $user),
            'user_id' => $user?->getKey(),
            'guard' => $event->guard,
            'email_fingerprint' => $this->emailFingerprint($event),
            ...$this->requestContext(),
        ]);
    }

    public function authenticationSucceeded(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        Log::info('Authentication succeeded.', [
            'security_event' => 'auth.login_succeeded',
            'user_id' => $event->user->getKey(),
            'guard' => $event->guard,
            'remember' => $event->remember,
            ...$this->requestContext(),
        ]);
    }

    public function passwordChanged(User $user): void
    {
        $requestContext = $this->requestContext();

        Log::notice('Password changed.', [
            'security_event' => 'security.password_changed',
            'user_id' => $user->getKey(),
            'actor_user_id' => Auth::id(),
            'change_source' => $this->passwordChangeSource($requestContext['source_path']),
            ...$requestContext,
        ]);
    }

    private function failureReason(Failed $event, ?User $user): string
    {
        if ($user === null) {
            return 'unknown_email';
        }

        if (! $this->passwordMatches($event, $user)) {
            return 'wrong_password';
        }

        $panel = Filament::getCurrentPanel();

        if (($panel !== null) && (! $user->canAccessPanel($panel))) {
            return 'panel_access_denied';
        }

        return 'authentication_rejected';
    }

    private function passwordMatches(Failed $event, User $user): bool
    {
        $password = $event->credentials['password'] ?? null;

        if (! is_string($password)) {
            return false;
        }

        try {
            return Hash::check($password, $user->getAuthPassword());
        } catch (Throwable) {
            return false;
        }
    }

    private function emailFingerprint(Failed $event): ?string
    {
        $email = $event->credentials['email'] ?? null;
        $key = config('app.key');

        if ((! is_string($email)) || (! is_string($key)) || ($key === '')) {
            return null;
        }

        return hash_hmac('sha256', Str::lower(mb_trim($email)), $key);
    }

    /**
     * @return array{
     *     request_id: ?string,
     *     panel: ?string,
     *     source_path: ?string,
     *     ip_address: ?string,
     *     user_agent: ?string,
     * }
     */
    private function requestContext(): array
    {
        $request = app()->bound('request') ? request() : null;

        if (! $request instanceof Request) {
            return [
                'request_id' => null,
                'panel' => Filament::getCurrentPanel()?->getId(),
                'source_path' => null,
                'ip_address' => null,
                'user_agent' => null,
            ];
        }

        if (! $request->attributes->has(self::REQUEST_ID_ATTRIBUTE)) {
            $request->attributes->set(self::REQUEST_ID_ATTRIBUTE, (string) Str::uuid());
        }

        $userAgent = $request->userAgent();

        return [
            'request_id' => $request->attributes->getString(self::REQUEST_ID_ATTRIBUTE),
            'panel' => Filament::getCurrentPanel()?->getId(),
            'source_path' => $this->sourcePath($request),
            'ip_address' => $request->ip(),
            'user_agent' => is_string($userAgent) ? mb_substr($userAgent, 0, 512) : null,
        ];
    }

    private function sourcePath(Request $request): string
    {
        $referer = $request->headers->get('referer');
        $refererPath = is_string($referer) ? parse_url($referer, PHP_URL_PATH) : null;

        if (is_string($refererPath) && ($refererPath !== '')) {
            return $refererPath;
        }

        return '/'.mb_ltrim($request->path(), '/');
    }

    private function passwordChangeSource(?string $sourcePath): string
    {
        if (app()->runningInConsole() && (($sourcePath === null) || ($sourcePath === '/'))) {
            return 'console';
        }

        if ($sourcePath === null) {
            return 'application';
        }

        return match (true) {
            str_contains($sourcePath, '/password-reset/reset') => 'password_reset',
            str_ends_with($sourcePath, '/my-profile') => 'profile',
            (bool) preg_match('#^/admin/users(?:/[^/]+)?$#', $sourcePath) => 'admin_user_management',
            str_ends_with($sourcePath, '/login') => 'login_request',
            default => 'application',
        };
    }
}
