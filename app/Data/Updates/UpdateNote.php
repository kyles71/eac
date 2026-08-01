<?php

declare(strict_types=1);

namespace App\Data\Updates;

final readonly class UpdateNote
{
    /**
     * @param  list<string>  $highlights
     * @param  list<string>  $testingFocus
     */
    public function __construct(
        public string $title,
        public string $summary,
        public array $highlights,
        public array $testingFocus,
    ) {}

    /**
     * @return array{title: string, summary: string, highlights: list<string>, testing_focus: list<string>}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'summary' => $this->summary,
            'highlights' => $this->highlights,
            'testing_focus' => $this->testingFocus,
        ];
    }
}
