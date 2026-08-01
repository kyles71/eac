<?php

declare(strict_types=1);

namespace App\Data\Updates;

use Carbon\CarbonImmutable;

final readonly class UpdatesFeed
{
    /**
     * @param  list<TestingUpdate>  $testingUpdates
     * @param  list<ProductionRelease>  $productionReleases
     */
    public function __construct(
        public array $testingUpdates,
        public array $productionReleases,
        public CarbonImmutable $refreshedAt,
        public ?CarbonImmutable $devDeployedAt,
        public bool $stale = false,
        public bool $unavailable = false,
        public ?string $statusMessage = null,
    ) {}

    public static function unavailable(string $message): self
    {
        return new self(
            testingUpdates: [],
            productionReleases: [],
            refreshedAt: CarbonImmutable::now(),
            devDeployedAt: null,
            unavailable: true,
            statusMessage: $message,
        );
    }

    public function degraded(string $message): self
    {
        return new self(
            testingUpdates: $this->testingUpdates,
            productionReleases: $this->productionReleases,
            refreshedAt: $this->refreshedAt,
            devDeployedAt: $this->devDeployedAt,
            stale: true,
            statusMessage: $message,
        );
    }

    /**
     * @return array{
     *     testing_updates: list<array<string, mixed>>,
     *     production_releases: list<array<string, mixed>>,
     *     refreshed_at_display: string,
     *     dev_deployed_at_display: string|null,
     *     stale: bool,
     *     unavailable: bool,
     *     status_message: string|null
     * }
     */
    public function toArray(): array
    {
        $timezone = (string) config('app.display_timezone', config('app.timezone'));

        return [
            'testing_updates' => array_map(
                static fn (TestingUpdate $update): array => $update->toArray(),
                $this->testingUpdates,
            ),
            'production_releases' => array_map(
                static fn (ProductionRelease $release): array => $release->toArray(),
                $this->productionReleases,
            ),
            'refreshed_at_display' => $this->refreshedAt->setTimezone($timezone)->format('M j, Y g:i A T'),
            'dev_deployed_at_display' => $this->devDeployedAt?->setTimezone($timezone)->format('M j, Y g:i A T'),
            'stale' => $this->stale,
            'unavailable' => $this->unavailable,
            'status_message' => $this->statusMessage,
        ];
    }
}
