<?php

declare(strict_types=1);

use App\Filament\Shared\Pages\Auth\Login as LoginPage;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');

    $request = Request::create('/livewire/update', 'POST');
    $request->headers->set('referer', 'https://example.test/admin/login?token=must-not-be-logged');
    $request->headers->set('user-agent', 'Security audit test browser');
    $request->server->set('REMOTE_ADDR', '203.0.113.10');

    app()->instance('request', $request);
});

it('logs a successful authentication without sensitive credentials', function (): void {
    $user = User::factory()->create();

    Log::spy();

    event(new Login('web', $user, true));

    Log::shouldHaveReceived('info')
        ->once()
        ->with('Authentication succeeded.', Mockery::on(function (array $context) use ($user): bool {
            expect($context)
                ->security_event->toBe('auth.login_succeeded')
                ->user_id->toBe($user->id)
                ->guard->toBe('web')
                ->remember->toBeTrue()
                ->panel->toBe('admin')
                ->source_path->toBe('/admin/login')
                ->ip_address->toBe('203.0.113.10')
                ->user_agent->toBe('Security audit test browser')
                ->request_id->toBeString();

            expect(json_encode($context))
                ->not->toContain($user->email)
                ->not->toContain($user->password)
                ->not->toContain('must-not-be-logged');

            return true;
        }));
});

it('classifies an unknown email authentication failure', function (): void {
    $email = 'missing@example.com';
    $password = 'not-the-password';

    Log::spy();

    event(new Failed('web', null, [
        'email' => $email,
        'password' => $password,
    ]));

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Authentication failed.', Mockery::on(function (array $context) use ($email, $password): bool {
            expect($context)
                ->security_event->toBe('auth.login_failed')
                ->failure_reason->toBe('unknown_email')
                ->user_id->toBeNull()
                ->email_fingerprint->toBeString();

            expect(json_encode($context))
                ->not->toContain($email)
                ->not->toContain($password)
                ->not->toContain('must-not-be-logged');

            return true;
        }));
});

it('classifies an incorrect password authentication failure', function (): void {
    $user = User::factory()->isOwner()->create();
    $password = 'not-the-password';

    Log::spy();

    event(new Failed('web', $user, [
        'email' => $user->email,
        'password' => $password,
    ]));

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Authentication failed.', Mockery::on(function (array $context) use ($user, $password): bool {
            expect($context)
                ->failure_reason->toBe('wrong_password')
                ->user_id->toBe($user->id);

            expect(json_encode($context))
                ->not->toContain($user->email)
                ->not->toContain($password)
                ->not->toContain($user->password);

            return true;
        }));
});

it('classifies a panel access denial after valid credentials', function (): void {
    $user = User::factory()->create();

    auth()->logout();
    Log::spy();

    livewire(LoginPage::class)
        ->fillForm([
            'email' => $user->email,
            'password' => 'password',
        ])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Authentication failed.', Mockery::on(function (array $context) use ($user): bool {
            expect($context)
                ->failure_reason->toBe('panel_access_denied')
                ->user_id->toBe($user->id)
                ->panel->toBe('admin');

            return true;
        }));
});

it('logs a password change without the hash or referrer query', function (): void {
    $actor = auth()->user();
    $user = User::factory()->create();

    $request = request();
    $request->headers->set('referer', "https://example.test/admin/users/{$user->id}?token=must-not-be-logged");

    Log::spy();

    $user->update(['password' => 'a-new-secure-password']);

    Log::shouldHaveReceived('notice')
        ->once()
        ->with('Password changed.', Mockery::on(function (array $context) use ($actor, $user): bool {
            expect($context)
                ->security_event->toBe('security.password_changed')
                ->user_id->toBe($user->id)
                ->actor_user_id->toBe($actor?->id)
                ->change_source->toBe('admin_user_management')
                ->source_path->toBe("/admin/users/{$user->id}");

            expect(json_encode($context))
                ->not->toContain($user->password)
                ->not->toContain('a-new-secure-password')
                ->not->toContain('must-not-be-logged');

            return true;
        }));
});

it('does not log an unrelated user update as a password change', function (): void {
    $user = User::factory()->create();

    Log::spy();

    $user->update(['first_name' => 'Updated']);

    Log::shouldNotHaveReceived('notice');
});
