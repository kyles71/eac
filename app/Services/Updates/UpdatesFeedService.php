<?php

declare(strict_types=1);

namespace App\Services\Updates;

use App\Data\Updates\ProductionRelease;
use App\Data\Updates\TestingUpdate;
use App\Data\Updates\UpdatesFeed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class UpdatesFeedService
{
    public function __construct(
        private GitHubUpdatesClientService $client,
        private UpdateNoteParserService $parser,
    ) {}

    public function get(): UpdatesFeed
    {
        $cached = Cache::get($this->freshCacheKey());

        if ($cached instanceof UpdatesFeed) {
            return $cached;
        }

        return $this->refresh();
    }

    public function refresh(): UpdatesFeed
    {
        try {
            $feed = $this->load();
            $ttl = max(1, (int) config('services.github_updates.cache_ttl', 300));

            Cache::put($this->freshCacheKey(), $feed, $ttl);
            Cache::forever($this->lastSuccessfulCacheKey(), $feed);

            return $feed;
        } catch (Throwable $exception) {
            Log::warning('Unable to refresh the GitHub Updates feed.', [
                'exception' => $exception,
            ]);

            $lastSuccessful = Cache::get($this->lastSuccessfulCacheKey());
            $message = 'GitHub could not be reached. The displayed information may be out of date.';

            if ($lastSuccessful instanceof UpdatesFeed) {
                return $lastSuccessful->degraded($message);
            }

            return UpdatesFeed::unavailable('Updates are temporarily unavailable because GitHub could not be reached.');
        }
    }

    private function load(): UpdatesFeed
    {
        $deployment = $this->client->latestSuccessfulDevDeployment();
        $testingUpdates = [];
        $devDeployedAt = null;

        if ($deployment !== null) {
            $devDeployedAt = CarbonImmutable::parse($deployment['deployed_at']);

            foreach ($this->client->openMasterPullRequests() as $pullRequest) {
                if (! $this->isApproved($pullRequest)) {
                    continue;
                }

                $body = $pullRequest['body'] ?? null;
                $branch = $pullRequest['head']['ref'] ?? null;
                $headSha = $pullRequest['head']['sha'] ?? null;

                if (! is_string($body) || ! is_string($branch) || ! is_string($headSha)) {
                    continue;
                }

                $note = $this->parser->first($body);

                if ($note === null || ! $this->client->commitIsContained($headSha, $deployment['sha'])) {
                    continue;
                }

                $testingUpdates[] = new TestingUpdate($branch, $note);
            }
        }

        usort(
            $testingUpdates,
            static fn (TestingUpdate $left, TestingUpdate $right): int => strnatcasecmp($left->note->title, $right->note->title),
        );

        $productionReleases = [];
        $limit = min(100, max(1, (int) config('services.github_updates.release_limit', 20)));

        foreach ($this->client->publishedReleases() as $release) {
            $body = $release['body'] ?? null;
            $version = $release['tag_name'] ?? null;
            $publishedAt = $release['published_at'] ?? null;

            if (! is_string($body) || ! is_string($version) || ! is_string($publishedAt)) {
                continue;
            }

            $notes = $this->parser->all($body);

            if ($notes === []) {
                continue;
            }

            $productionReleases[] = new ProductionRelease(
                version: $version,
                publishedAt: CarbonImmutable::parse($publishedAt),
                notes: $notes,
            );

            if (count($productionReleases) >= $limit) {
                break;
            }
        }

        return new UpdatesFeed(
            testingUpdates: $testingUpdates,
            productionReleases: $productionReleases,
            refreshedAt: CarbonImmutable::now(),
            devDeployedAt: $devDeployedAt,
        );
    }

    /** @param array<string, mixed> $pullRequest */
    private function isApproved(array $pullRequest): bool
    {
        $labels = collect($pullRequest['labels'] ?? [])
            ->filter(fn (mixed $label): bool => is_array($label) && is_string($label['name'] ?? null))
            ->pluck('name');

        return $labels->contains('updates-approved') && ! $labels->contains('skip-updates');
    }

    private function freshCacheKey(): string
    {
        return $this->cacheKey('fresh');
    }

    private function lastSuccessfulCacheKey(): string
    {
        return $this->cacheKey('last-successful');
    }

    private function cacheKey(string $suffix): string
    {
        return 'github-updates.'.sha1((string) config('services.github_updates.repository')).'.'.$suffix;
    }
}
