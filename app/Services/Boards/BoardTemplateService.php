<?php

declare(strict_types=1);

namespace App\Services\Boards;

use App\Enums\BoardInteractionMode;
use App\Enums\BoardItemType;
use App\Enums\BoardMemberRole;
use App\Enums\BoardStageKind;
use App\Enums\BoardTemplate;
use App\Models\Board;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class BoardTemplateService
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, User $creator): Board
    {
        $templateValue = $attributes['template'] ?? BoardTemplate::GeneralKanban;
        $template = $templateValue instanceof BoardTemplate
            ? $templateValue
            : BoardTemplate::from((string) $templateValue);
        $stages = $template === BoardTemplate::Blank
            ? $this->customStages($attributes['custom_stages'] ?? [])
            : $this->stagesFor($template);

        if ($stages === []) {
            throw new InvalidArgumentException('A board must have at least one stage.');
        }

        return DB::transaction(function () use ($attributes, $creator, $template, $stages): Board {
            $name = mb_trim((string) $attributes['name']);
            $board = Board::query()->create([
                'created_by_user_id' => $creator->id,
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'description' => $attributes['description'] ?? null,
                'interaction_mode' => $attributes['interaction_mode'] ?? $this->modeFor($template),
                'allowed_item_types' => $attributes['allowed_item_types'] ?? $this->itemTypesFor($template),
                'archived_at' => null,
            ]);

            foreach ($stages as $index => $stage) {
                $board->stages()->create([
                    'name' => $stage['name'],
                    'subtitle' => $stage['subtitle'] ?? null,
                    'color' => $stage['color'],
                    'sort_order' => ($index + 1) * 10,
                    'kind' => $stage['kind'],
                    'is_default' => $index === 0,
                    'archived_at' => null,
                ]);
            }

            $board->memberships()->create([
                'user_id' => $creator->id,
                'role' => BoardMemberRole::Manager,
            ]);

            return $board->refresh();
        });
    }

    /** @return list<array{name: string, color: string, kind: BoardStageKind}> */
    public function stagesFor(BoardTemplate $template): array
    {
        return match ($template) {
            BoardTemplate::ProductFeedback => [
                ['name' => 'Future Ideas', 'color' => 'gray', 'kind' => BoardStageKind::Active],
                ['name' => 'Planning', 'color' => 'info', 'kind' => BoardStageKind::Active],
                ['name' => 'Ready to Build', 'color' => 'primary', 'kind' => BoardStageKind::Active],
                ['name' => 'In Progress', 'color' => 'warning', 'kind' => BoardStageKind::Active],
                ['name' => 'Ready for Testing', 'color' => 'info', 'kind' => BoardStageKind::Active],
                ['name' => 'Released', 'color' => 'success', 'kind' => BoardStageKind::Completed],
                ['name' => 'Not Planned', 'color' => 'gray', 'kind' => BoardStageKind::Cancelled],
            ],
            BoardTemplate::GeneralKanban => [
                ['name' => 'Backlog', 'color' => 'gray', 'kind' => BoardStageKind::Active],
                ['name' => 'To Do', 'color' => 'info', 'kind' => BoardStageKind::Active],
                ['name' => 'In Progress', 'color' => 'warning', 'kind' => BoardStageKind::Active],
                ['name' => 'Review', 'color' => 'primary', 'kind' => BoardStageKind::Active],
                ['name' => 'Done', 'color' => 'success', 'kind' => BoardStageKind::Completed],
            ],
            BoardTemplate::Blank => [],
        };
    }

    public function modeFor(BoardTemplate $template): BoardInteractionMode
    {
        return match ($template) {
            BoardTemplate::ProductFeedback => BoardInteractionMode::Moderated,
            BoardTemplate::GeneralKanban, BoardTemplate::Blank => BoardInteractionMode::Collaborative,
        };
    }

    /** @return list<string> */
    public function itemTypesFor(BoardTemplate $template): array
    {
        return match ($template) {
            BoardTemplate::ProductFeedback => [
                BoardItemType::Bug->value,
                BoardItemType::FeatureRequest->value,
                BoardItemType::Idea->value,
            ],
            BoardTemplate::GeneralKanban, BoardTemplate::Blank => [BoardItemType::Task->value],
        };
    }

    /** @return list<array{name: string, subtitle: ?string, color: string, kind: BoardStageKind}> */
    private function customStages(mixed $stages): array
    {
        if (! is_array($stages)) {
            return [];
        }

        return collect($stages)
            ->filter(fn (mixed $stage): bool => is_array($stage) && filled($stage['name'] ?? null))
            ->map(fn (array $stage): array => [
                'name' => mb_trim((string) $stage['name']),
                'subtitle' => filled($stage['subtitle'] ?? null)
                    ? mb_trim((string) $stage['subtitle'])
                    : null,
                'color' => (string) ($stage['color'] ?? 'gray'),
                'kind' => $stage['kind'] instanceof BoardStageKind
                    ? $stage['kind']
                    : BoardStageKind::from((string) ($stage['kind'] ?? BoardStageKind::Active->value)),
            ])
            ->values()
            ->all();
    }

    private function uniqueSlug(string $name): string
    {
        $baseSlug = Str::slug($name) ?: 'board';
        $slug = $baseSlug;
        $suffix = 2;

        while (Board::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
