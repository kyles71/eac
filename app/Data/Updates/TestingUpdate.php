<?php

declare(strict_types=1);

namespace App\Data\Updates;

use Carbon\CarbonImmutable;

final readonly class TestingUpdate
{
    public function __construct(
        public string $branch,
        public UpdateNote $note,
        public CarbonImmutable $deployedAt,
    ) {}

    /**
     * @return array{branch: string, note: array{title: string, summary: string, highlights: list<string>, testing_focus: list<string>}, deployed_at: string, deployed_at_display: string}
     */
    public function toArray(): array
    {
        return [
            'branch' => $this->branch,
            'note' => $this->note->toArray(),
            'deployed_at' => $this->deployedAt->toIso8601String(),
            'deployed_at_display' => $this->deployedAt
                ->setTimezone((string) config('app.display_timezone', config('app.timezone')))
                ->format('M j, Y g:i A T'),
        ];
    }
}
