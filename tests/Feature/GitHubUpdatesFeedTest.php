<?php

declare(strict_types=1);

use App\Services\Updates\GitHubUpdatesClientService;
use App\Services\Updates\UpdatesFeedService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();
    CarbonImmutable::setTestNow('2026-07-31 16:00:00');
    config()->set('services.github_updates.token', 'github-token');
    config()->set('services.github_updates.repository', 'kyles71/eac');
    config()->set('services.github_updates.cache_ttl', 300);
    config()->set('services.github_updates.release_limit', 20);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('shows multiple approved feature heads contained in the latest successful dev deployment', function (): void {
    Http::fake(function (Request $request) {
        $url = $request->url();

        if (str_contains($url, '/deployments/12/statuses')) {
            return Http::response([['state' => 'success', 'created_at' => '2026-07-31T14:30:00Z']]);
        }

        if (str_contains($url, '/deployments')) {
            return Http::response([['id' => 12, 'sha' => 'dev-head', 'created_at' => '2026-07-31T14:25:00Z']]);
        }

        if (str_contains($url, '/pulls')) {
            return Http::response([
                feedPullRequest('feature/alpha', 'alpha-sha', 'Alpha improvements'),
                feedPullRequest('feature/beta', 'beta-sha', 'Beta improvements'),
                feedPullRequest('feature/stale', 'stale-sha', 'Stale improvements'),
                feedPullRequest('feature/unapproved', 'unapproved-sha', 'Unapproved', []),
                feedPullRequest('feature/skipped', 'skipped-sha', 'Skipped', ['updates-approved', 'skip-updates']),
                feedPullRequest('feature/malformed', 'malformed-sha', 'Malformed', ['updates-approved'], 'not a note'),
            ]);
        }

        if (str_contains($url, '/compare/alpha-sha...dev-head')) {
            return Http::response(['status' => 'ahead']);
        }

        if (str_contains($url, '/compare/beta-sha...dev-head')) {
            return Http::response(['status' => 'identical']);
        }

        if (str_contains($url, '/compare/stale-sha...dev-head')) {
            return Http::response(['status' => 'diverged']);
        }

        if (str_contains($url, '/releases')) {
            return Http::response([
                feedRelease('v1.260731.2', feedNote('Released alpha')."\n\n".feedNote('Released beta')),
                feedRelease('v1.260731.1', 'Generated technical notes only.'),
                feedRelease('v1.260730.1', feedNote('Draft release'), draft: true),
                feedRelease('v1.260729.1', feedNote('Prerelease'), prerelease: true),
            ]);
        }

        return Http::response([], 404);
    });

    $feed = app(UpdatesFeedService::class)->refresh();

    expect($feed->testingUpdates)->toHaveCount(2)
        ->and(array_map(fn ($update): string => $update->branch, $feed->testingUpdates))->toBe([
            'feature/alpha',
            'feature/beta',
        ])
        ->and($feed->productionReleases)->toHaveCount(1)
        ->and($feed->productionReleases[0]->version)->toBe('v1.260731.2')
        ->and($feed->productionReleases[0]->notes)->toHaveCount(2)
        ->and($feed->stale)->toBeFalse()
        ->and($feed->unavailable)->toBeFalse();
});

it('uses the previous successful deployment when a newer deployment failed', function (): void {
    Http::fake(function (Request $request) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/deployments/20/statuses') => Http::response([['state' => 'failure', 'created_at' => '2026-07-31T15:00:00Z']]),
            str_contains($url, '/deployments/19/statuses') => Http::response([['state' => 'success', 'created_at' => '2026-07-31T14:00:00Z']]),
            str_contains($url, '/deployments') => Http::response([
                ['id' => 20, 'sha' => 'failed-head'],
                ['id' => 19, 'sha' => 'successful-head'],
            ]),
            str_contains($url, '/pulls') => Http::response([
                feedPullRequest('feature/alpha', 'alpha-sha', 'Alpha improvements'),
            ]),
            str_contains($url, '/compare/alpha-sha...successful-head') => Http::response(['status' => 'ahead']),
            str_contains($url, '/releases') => Http::response([]),
            default => Http::response([], 404),
        };
    });

    $feed = app(UpdatesFeedService::class)->refresh();

    expect($feed->testingUpdates)->toHaveCount(1)
        ->and($feed->devDeployedAt?->toIso8601String())->toBe('2026-07-31T14:00:00+00:00');
});

it('returns the last successful snapshot as stale when GitHub becomes unavailable', function (): void {
    Http::fake(fn (Request $request) => match (true) {
        str_contains($request->url(), '/deployments/12/statuses') => Http::response([['state' => 'success', 'created_at' => '2026-07-31T14:30:00Z']]),
        str_contains($request->url(), '/deployments') => Http::response([['id' => 12, 'sha' => 'dev-head']]),
        str_contains($request->url(), '/pulls') => Http::response([]),
        str_contains($request->url(), '/releases') => Http::response([feedRelease('v1.260731.1', feedNote('Available snapshot'))]),
        default => Http::response([], 404),
    });

    $service = app(UpdatesFeedService::class);
    $service->refresh();

    Http::fake(['*' => Http::failedConnection()]);
    $stale = $service->refresh();

    expect($stale->stale)->toBeTrue()
        ->and($stale->unavailable)->toBeFalse()
        ->and($stale->productionReleases[0]->notes[0]->title)->toBe('Available snapshot');
});

it('uses the fresh cache without calling GitHub again', function (): void {
    Http::fake(fn (Request $request) => match (true) {
        str_contains($request->url(), '/deployments') => Http::response([]),
        str_contains($request->url(), '/releases') => Http::response([feedRelease('v1.260731.1', feedNote('Cached release'))]),
        default => Http::response([], 404),
    });

    $service = app(UpdatesFeedService::class);
    $service->refresh();

    Http::fake(fn (): never => throw new RuntimeException('GitHub should not be called for a fresh cache entry.'));
    $cached = $service->get();

    expect($cached->productionReleases[0]->notes[0]->title)->toBe('Cached release')
        ->and($cached->stale)->toBeFalse();
});

it('returns an unavailable feed when no token or cached snapshot exists', function (): void {
    config()->set('services.github_updates.token');

    $feed = app(UpdatesFeedService::class)->refresh();

    expect($feed->unavailable)->toBeTrue()
        ->and($feed->testingUpdates)->toBeEmpty()
        ->and($feed->productionReleases)->toBeEmpty();
});

it('paginates pull requests and applies the release limit after marker validation', function (): void {
    config()->set('services.github_updates.release_limit', 2);

    Http::fake(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $page = (int) ($query['page'] ?? 1);

        if (str_contains($request->url(), '/pulls')) {
            return Http::response($page === 1
                ? array_fill(0, 100, ['number' => 1])
                : [['number' => 101]]);
        }

        if (str_contains($request->url(), '/releases')) {
            return Http::response([
                feedRelease('v1.260731.4', 'Generated technical notes only.'),
                feedRelease('v1.260731.3', feedNote('Release 3')),
                feedRelease('v1.260731.2', feedNote('Release 2')),
                feedRelease('v1.260731.1', feedNote('Release 1')),
            ]);
        }

        if (str_contains($request->url(), '/deployments')) {
            return Http::response([]);
        }

        return Http::response([], 404);
    });

    $client = app(GitHubUpdatesClientService::class);

    expect($client->openMasterPullRequests())->toHaveCount(101);

    $feed = app(UpdatesFeedService::class)->refresh();

    expect($feed->productionReleases)->toHaveCount(2)
        ->and(array_map(
            fn ($release): string => $release->version,
            $feed->productionReleases,
        ))->toBe(['v1.260731.3', 'v1.260731.2']);
});

/** @return array<string, mixed> */
function feedPullRequest(
    string $branch,
    string $sha,
    string $title,
    array $labels = ['updates-approved'],
    ?string $body = null,
): array {
    return [
        'body' => $body ?? feedNote($title),
        'labels' => array_map(fn (string $label): array => ['name' => $label], $labels),
        'head' => ['ref' => $branch, 'sha' => $sha],
    ];
}

/** @return array<string, mixed> */
function feedRelease(string $tag, string $body, bool $draft = false, bool $prerelease = false): array
{
    return [
        'tag_name' => $tag,
        'published_at' => '2026-07-31T15:00:00Z',
        'draft' => $draft,
        'prerelease' => $prerelease,
        'body' => $body,
    ];
}

function feedNote(string $title): string
{
    return <<<MARKDOWN
<!-- eac-update-note:start -->
### {$title}

This change is ready for staff to review.

#### Highlights
- Staff can use the updated behavior.

#### Testing focus
- Confirm the behavior on dev.
<!-- eac-update-note:end -->
MARKDOWN;
}
