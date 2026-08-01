<?php

declare(strict_types=1);

namespace App\Data\Updates;

use Carbon\CarbonImmutable;

final readonly class ProductionRelease
{
    /**
     * @param  list<UpdateNote>  $notes
     */
    public function __construct(
        public string $version,
        public CarbonImmutable $publishedAt,
        public array $notes,
    ) {}

    /**
     * @return array{version: string, published_at_display: string, notes: list<array{title: string, summary: string, highlights: list<string>, testing_focus: list<string>}>}
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'published_at_display' => $this->publishedAt
                ->setTimezone((string) config('app.display_timezone', config('app.timezone')))
                ->format('M j, Y g:i A T'),
            'notes' => array_map(
                static fn (UpdateNote $note): array => $note->toArray(),
                $this->notes,
            ),
        ];
    }
}
