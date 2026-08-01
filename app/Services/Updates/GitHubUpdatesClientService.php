<?php

declare(strict_types=1);

namespace App\Services\Updates;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class GitHubUpdatesClientService
{
    /** @return list<array<string, mixed>> */
    public function openMasterPullRequests(): array
    {
        return $this->paginate('/pulls', [
            'state' => 'open',
            'base' => 'master',
            'sort' => 'updated',
            'direction' => 'desc',
        ]);
    }

    public function firstMergedIntoDevAt(string $branch): ?string
    {
        $owner = explode('/', $this->repository(), 2)[0];
        $pullRequests = $this->paginate('/pulls', [
            'state' => 'closed',
            'base' => 'dev',
            'head' => $owner.':'.$branch,
            'sort' => 'created',
            'direction' => 'asc',
        ]);
        $firstMergedAt = null;

        foreach ($pullRequests as $pullRequest) {
            $mergedAt = $pullRequest['merged_at'] ?? null;

            if (is_string($mergedAt) && ($firstMergedAt === null || strcmp($mergedAt, $firstMergedAt) < 0)) {
                $firstMergedAt = $mergedAt;
            }
        }

        return $firstMergedAt;
    }

    /**
     * @return array{sha: string, deployed_at: string}|null
     */
    public function latestSuccessfulDevDeployment(): ?array
    {
        $deployments = $this->get('/deployments', [
            'environment' => 'dev',
            'per_page' => 100,
        ]);

        foreach ($deployments as $deployment) {
            if (! is_array($deployment) || ! isset($deployment['id'], $deployment['sha'])) {
                continue;
            }

            $statuses = $this->get('/deployments/'.$deployment['id'].'/statuses', [
                'per_page' => 1,
            ]);
            $latestStatus = $statuses[0] ?? null;

            if (! is_array($latestStatus) || ($latestStatus['state'] ?? null) !== 'success') {
                continue;
            }

            $deployedAt = $latestStatus['created_at'] ?? $deployment['updated_at'] ?? $deployment['created_at'] ?? null;

            if (! is_string($deployment['sha']) || ! is_string($deployedAt)) {
                continue;
            }

            return [
                'sha' => $deployment['sha'],
                'deployed_at' => $deployedAt,
            ];
        }

        return null;
    }

    public function commitIsContained(string $commitSha, string $deploymentSha): bool
    {
        $comparison = $this->get('/compare/'.rawurlencode($commitSha).'...'.rawurlencode($deploymentSha));
        $status = $comparison['status'] ?? null;

        return $status === 'ahead' || $status === 'identical';
    }

    /** @return list<array<string, mixed>> */
    public function publishedReleases(): array
    {
        $releases = $this->paginate('/releases', []);

        return array_values(array_filter(
            $releases,
            static fn (array $release): bool => ($release['draft'] ?? true) === false
                && ($release['prerelease'] ?? true) === false
                && is_string($release['published_at'] ?? null),
        ));
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>|list<mixed>
     */
    private function get(string $path, array $query = []): array
    {
        $response = $this->request()->get($path, $query)->throw();
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('GitHub returned an unexpected response.');
        }

        return $payload;
    }

    /**
     * @param  array<string, scalar>  $query
     * @return list<array<string, mixed>>
     */
    private function paginate(string $path, array $query): array
    {
        $items = [];

        for ($page = 1; $page <= 10; $page++) {
            $payload = $this->get($path, [
                ...$query,
                'per_page' => 100,
                'page' => $page,
            ]);

            if (! array_is_list($payload)) {
                throw new RuntimeException('GitHub returned an unexpected paginated response.');
            }

            foreach ($payload as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }

            if (count($payload) < 100) {
                break;
            }
        }

        return $items;
    }

    private function request(): PendingRequest
    {
        $token = config('services.github_updates.token');

        if (! is_string($token) || mb_trim($token) === '') {
            throw new RuntimeException('The GitHub Updates token is not configured.');
        }

        return Http::baseUrl('https://api.github.com/repos/'.$this->repository())
            ->withToken($token)
            ->acceptJson()
            ->withHeaders([
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'EAC-Updates-Page',
            ])
            ->connectTimeout(5)
            ->timeout(10)
            ->retry(2, 250, throw: false);
    }

    private function repository(): string
    {
        $repository = config('services.github_updates.repository');

        if (! is_string($repository) || preg_match('/\A[^\/]+\/[^\/]+\z/', $repository) !== 1) {
            throw new RuntimeException('The GitHub Updates repository is not configured correctly.');
        }

        return $repository;
    }
}
