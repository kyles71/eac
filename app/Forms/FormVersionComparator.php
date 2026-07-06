<?php

declare(strict_types=1);

namespace App\Forms;

use App\Models\FormVersion;

final readonly class FormVersionComparator
{
    /**
     * @return array{added: array<int, string>, removed: array<int, string>, changed: array<int, string>, moved: array<int, string>}
     */
    public function compare(FormVersion $left, FormVersion $right): array
    {
        $leftBlocks = collect($this->flatten($left->schema))->keyBy('identity');
        $rightBlocks = collect($this->flatten($right->schema))->keyBy('identity');

        return [
            'added' => $rightBlocks->keys()->diff($leftBlocks->keys())->values()->all(),
            'removed' => $leftBlocks->keys()->diff($rightBlocks->keys())->values()->all(),
            'changed' => $leftBlocks->keys()
                ->intersect($rightBlocks->keys())
                ->filter(fn (string $identity): bool => $leftBlocks[$identity]['data'] !== $rightBlocks[$identity]['data'])
                ->values()
                ->all(),
            'moved' => $leftBlocks->keys()
                ->intersect($rightBlocks->keys())
                ->filter(fn (string $identity): bool => $leftBlocks[$identity]['position'] !== $rightBlocks[$identity]['position'])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $schema
     * @return array<int, array{identity: string, position: string, data: array<string, mixed>}>
     */
    private function flatten(array $schema, string $parent = 'root'): array
    {
        $flattened = [];

        foreach ($schema as $position => $block) {
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $identity = (string) ($data['key'] ?? "{$parent}:{$position}:".($block['type'] ?? 'unknown'));
            $children = $data['components'] ?? [];
            unset($data['components']);

            $flattened[] = [
                'identity' => $identity,
                'position' => "{$parent}.{$position}",
                'data' => ['type' => $block['type'] ?? null, ...$data],
            ];

            if (is_array($children)) {
                $flattened = [...$flattened, ...$this->flatten($children, $identity)];
            }
        }

        return $flattened;
    }
}
