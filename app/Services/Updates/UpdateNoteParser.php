<?php

declare(strict_types=1);

namespace App\Services\Updates;

use App\Data\Updates\UpdateNote;

final class UpdateNoteParser
{
    public const string USER_START = '<!-- eac-update-note:start -->';

    public const string USER_END = '<!-- eac-update-note:end -->';

    public const string OPERATIONS_START = '<!-- eac-operational-notes:start -->';

    public const string OPERATIONS_END = '<!-- eac-operational-notes:end -->';

    public function first(string $markdown): ?UpdateNote
    {
        return $this->all($markdown)[0] ?? null;
    }

    /** @return list<UpdateNote> */
    public function all(string $markdown): array
    {
        preg_match_all(
            '/'.preg_quote(self::USER_START, '/').'(.*?)'.preg_quote(self::USER_END, '/').'/s',
            str_replace("\r\n", "\n", $markdown),
            $matches,
        );

        $notes = [];

        foreach ($matches[1] as $block) {
            $note = $this->parseBlock(mb_trim($block));

            if ($note instanceof UpdateNote) {
                $notes[] = $note;
            }
        }

        return $notes;
    }

    private function parseBlock(string $block): ?UpdateNote
    {
        $matched = preg_match(
            '/\A###\s+([^\n]+)\n+(.+?)\n+####\s+Highlights\s*\n(.+?)\n+####\s+Testing focus\s*\n(.+)\z/s',
            $block,
            $parts,
        );

        if ($matched !== 1) {
            return null;
        }

        $title = mb_trim($parts[1]);
        $summary = mb_trim($parts[2]);
        $highlights = $this->bullets($parts[3]);
        $testingFocus = $this->bullets($parts[4]);

        if ($title === '' || $summary === '' || $highlights === [] || $testingFocus === []) {
            return null;
        }

        return new UpdateNote($title, $summary, $highlights, $testingFocus);
    }

    /** @return list<string> */
    private function bullets(string $markdown): array
    {
        preg_match_all('/^\s*-\s+(.+?)\s*$/m', $markdown, $matches);

        return array_values(array_filter(
            array_map(
                static fn (string $bullet): string => mb_trim($bullet),
                $matches[1],
            ),
            static fn (string $bullet): bool => $bullet !== '',
        ));
    }
}
