<?php

declare(strict_types=1);

namespace App\Data\Updates;

final readonly class TestingUpdate
{
    public function __construct(
        public string $branch,
        public UpdateNote $note,
    ) {}

    /**
     * @return array{branch: string, note: array{title: string, summary: string, highlights: list<string>, testing_focus: list<string>}}
     */
    public function toArray(): array
    {
        return [
            'branch' => $this->branch,
            'note' => $this->note->toArray(),
        ];
    }
}
