<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\Updates;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    Cache::flush();
    config()->set('services.github_updates.token', 'github-token');
    config()->set('services.github_updates.repository', 'kyles71/eac');

    Http::fake(function (Request $request) {
        $path = $request->url();
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);

        if (str_contains($path, '/deployments/9/statuses')) {
            return Http::response([[
                'state' => 'success',
                'created_at' => '2026-07-31T14:00:00Z',
            ]]);
        }

        if (str_contains($path, '/deployments')) {
            return Http::response([[
                'id' => 9,
                'sha' => 'dev-sha',
                'created_at' => '2026-07-31T13:58:00Z',
            ]]);
        }

        if (str_contains($path, '/compare/feature-sha...dev-sha')) {
            return Http::response(['status' => 'ahead']);
        }

        if (str_contains($path, '/pulls') && ($query['base'] ?? null) === 'dev') {
            return Http::response([[
                'merged_at' => '2026-07-30T13:30:00Z',
            ]]);
        }

        if (str_contains($path, '/pulls')) {
            return Http::response([[
                'body' => updatesPageNote('Friendlier account management'),
                'labels' => [['name' => 'updates-approved']],
                'head' => ['ref' => 'feature/accounts', 'sha' => 'feature-sha'],
            ]]);
        }

        if (str_contains($path, '/releases')) {
            return Http::response([[
                'tag_name' => 'v1.260731.1',
                'published_at' => '2026-07-31T15:00:00Z',
                'draft' => false,
                'prerelease' => false,
                'body' => updatesPageNote('Friendlier account management'),
            ]]);
        }

        return Http::response([], 404);
    });
});

it('shows the admin updates page to permitted users', function (): void {
    $owner = User::factory()->isOwner()->create();

    expect($owner->can('View:AppUpdatesPage'))->toBeTrue();

    $response = $this->actingAs($owner)
        ->get(Updates::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee('Available for testing')
        ->assertSee('Friendlier account management')
        ->assertSee('feature/accounts')
        ->assertSee('Merged into dev Jul 30, 2026 9:30 AM EDT')
        ->assertSee('Dev last deployed Jul 31, 2026 10:00 AM EDT')
        ->assertSee('fi-collapsible', escape: false)
        ->assertSee('<strong>account details</strong>', escape: false)
        ->assertSee('<a href="https://example.com/guidance">clearer guidance</a>', escape: false)
        ->assertSee('<code>requirements</code>', escape: false)
        ->assertSee('v1.260731.1');

    expect(mb_substr_count((string) $response->getContent(), 'data-update-timestamp-toggle'))->toBe(2);
});

it('hides and forbids updates without the dedicated permission', function (): void {
    $teacher = User::factory()->isTeacher()->create();

    $this->actingAs($teacher);

    expect(Updates::canAccess())->toBeFalse();

    $this->get(Updates::getUrl(panel: 'admin'))->assertForbidden();
});

it('grants the updates permission to super administrators and owners only by default', function (): void {
    $owner = User::factory()->isOwner()->create();
    $teacher = User::factory()->isTeacher()->create();

    expect(Role::findByName(Role::SUPER_ADMIN)->hasPermissionTo('View:AppUpdatesPage'))->toBeTrue()
        ->and($owner->can('View:AppUpdatesPage'))->toBeTrue()
        ->and($teacher->can('View:AppUpdatesPage'))->toBeFalse();
});

it('adds and removes the updates permission through its migration', function (): void {
    $migration = require database_path('migrations/2026_07_31_174158_add_view_app_updates_page_permission.php');

    $migration->down();

    expect(Permission::query()->where('name', 'View:AppUpdatesPage')->exists())->toBeFalse();

    $migration->up();

    expect(Role::findByName(Role::SUPER_ADMIN)->hasPermissionTo('View:AppUpdatesPage'))->toBeTrue()
        ->and(Role::findByName('owner')->hasPermissionTo('View:AppUpdatesPage'))->toBeTrue();
});

it('does not register the updates page in the user panel', function (): void {
    expect(Filament::getPanel('user')->getPages())->not->toContain(Updates::class);
});

it('refreshes the feed from the header action', function (): void {
    livewire(Updates::class)
        ->callAction('refresh')
        ->assertNotified('Updates refreshed')
        ->assertSee('Friendlier account management');
});

it('warns when a manual refresh falls back to stale data', function (): void {
    $owner = User::factory()->isOwner()->create();

    $this->actingAs($owner);

    $component = livewire(Updates::class);

    Http::fake(['*' => Http::failedConnection()]);

    $component
        ->callAction('refresh')
        ->assertNotified('Using cached updates')
        ->assertSee('The displayed information may be out of date.');
});

function updatesPageNote(string $title): string
{
    return <<<MARKDOWN
<!-- eac-update-note:start -->
### {$title}

Staff can manage **account details** with [clearer guidance](https://example.com/guidance).

#### Highlights
- Account `requirements` are easier to understand.

#### Testing focus
- Review the account page on dev.
<!-- eac-update-note:end -->
MARKDOWN;
}
