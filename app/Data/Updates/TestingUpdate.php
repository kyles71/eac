<?php

declare(strict_types=1);

namespace App\Data\Updates;

use Carbon\CarbonImmutable;

final readonly class TestingUpdate
{
    public function __construct(
        public string $branch,
        public ?CarbonImmutable $mergedIntoDevAt,
        public UpdateNote $note,
    ) {}

    /**
     * @return array{branch: string, merged_into_dev_at_display: string|null, note: array{title: string, summary: string, highlights: list<string>, testing_focus: list<string>}}
     */
    public function toArray(): array
    {
        return [
            'branch' => $this->branch,
            'merged_into_dev_at_display' => $this->mergedIntoDevAt
                ?->setTimezone((string) config('app.display_timezone', config('app.timezone')))
                ->format('M j, Y g:i A T'),
            'note' => $this->note->toArray(),
        ];
    }
}
