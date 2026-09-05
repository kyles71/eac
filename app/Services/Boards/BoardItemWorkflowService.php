<?php

declare(strict_types=1);

namespace App\Services\Boards;

use App\Enums\BoardInteractionMode;
use App\Enums\BoardItemActivityType;
use App\Enums\BoardItemPriority;
use App\Enums\BoardItemType;
use App\Enums\BoardMemberRole;
use App\Models\Board;
use App\Models\BoardItem;
use App\Models\BoardItemActivity;
use App\Models\BoardItemComment;
use App\Models\BoardMembership;
use App\Models\BoardStage;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Relaticle\Flowforge\Services\DecimalPosition;

final class BoardItemWorkflowService
{
    public function __construct(private readonly BoardNotificationService $notifications) {}

    /** @param array<string, mixed> $attributes */
    public function create(Board $board, BoardStage $requestedStage, User $actor, array $attributes): BoardItem
    {
        Gate::forUser($actor)->authorize('createItem', $board);

        $role = $board->membershipRoleFor($actor);
        $stage = $board->interaction_mode === BoardInteractionMode::Moderated
            && ! $role?->canManage()
            && ! $actor->can('Update:Board')
                ? $board->defaultStage()
                : $requestedStage;

        if ($stage === null || $stage->board_id !== $board->id || $stage->archived_at !== null) {
            throw new InvalidArgumentException('The selected stage is not available on this board.');
        }

        $type = $attributes['type'] instanceof BoardItemType
            ? $attributes['type']
            : BoardItemType::from((string) $attributes['type']);

        if (! $board->allowsItemType($type)) {
            throw new InvalidArgumentException('The selected item type is not available on this board.');
        }

        $canManageWorkflow = Gate::forUser($actor)->allows('manageWorkflow', $board);

        return DB::transaction(function () use ($board, $stage, $actor, $attributes, $type, $canManageWorkflow): BoardItem {
            $lastPosition = BoardItem::query()
                ->where('board_stage_id', $stage->id)
                ->whereNotNull('position')
                ->max('position');

            $item = BoardItem::query()->create([
                'board_id' => $board->id,
                'board_stage_id' => $stage->id,
                'created_by_user_id' => $actor->id,
                'type' => $type,
                'priority' => $canManageWorkflow
                    ? ($attributes['priority'] ?? BoardItemPriority::Medium)
                    : BoardItemPriority::Medium,
                'title' => mb_trim((string) $attributes['title']),
                'description' => $attributes['description'] ?? null,
                'position' => $lastPosition === null
                    ? DecimalPosition::forEmptyColumn()
                    : DecimalPosition::after((string) $lastPosition),
                'due_date' => $canManageWorkflow ? ($attributes['due_date'] ?? null) : null,
                'related_url' => $canManageWorkflow ? ($attributes['related_url'] ?? null) : null,
                'archived_at' => null,
            ]);

            $this->recordActivity($item, $actor, BoardItemActivityType::Created);
            $this->notifications->followAutomatically($item, $actor);
            $this->notifications->notifyNewSubmission($item, $actor);

            return $item;
        });
    }

    public function recordStageChange(BoardItem $item, BoardStage $from, BoardStage $to, User $actor): void
    {
        if ($from->is($to)) {
            return;
        }

        $this->recordActivity($item, $actor, BoardItemActivityType::StageChanged, [
            'from_id' => $from->id,
            'from_name' => $from->name,
            'to_id' => $to->id,
            'to_name' => $to->name,
        ]);

        $this->notifications->notifyFollowers(
            $item,
            $actor,
            'Board card moved',
            $actor->getFilamentName().' moved “'.$item->title.'” to '.$to->name.'.',
        );
    }

    /** @param list<int> $assigneeIds */
    public function syncAssignees(BoardItem $item, array $assigneeIds, User $actor): void
    {
        Gate::forUser($actor)->authorize('assign', $item);

        $this->syncAssigneesAfterAuthorization($item, $assigneeIds, $actor);
    }

    public function removeMemberAssignments(BoardMembership $membership, User $actor): void
    {
        Gate::forUser($actor)->authorize('manageMembers', $membership->board);

        BoardItem::query()
            ->where('board_id', $membership->board_id)
            ->whereHas('assignees', fn (Builder $query): Builder => $query->whereKey($membership->user_id))
            ->each(function (BoardItem $item) use ($membership, $actor): void {
                $remainingIds = $item->assignees()
                    ->whereKeyNot($membership->user_id)
                    ->pluck('users.id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();

                $this->syncAssigneesAfterAuthorization($item, $remainingIds, $actor);
            });
    }

    public function commentCreated(BoardItemComment $comment, User $actor): void
    {
        Gate::forUser($actor)->authorize('comment', $comment->item);
        $this->notifications->followAutomatically($comment->item, $actor);
        $this->notifications->notifyFollowers(
            $comment->item,
            $actor,
            'New board comment',
            $actor->getFilamentName().' commented on “'.$comment->item->title.'”.',
        );
    }

    public function archive(BoardItem $item, User $actor): void
    {
        Gate::forUser($actor)->authorize('archive', $item);

        if ($item->isArchived()) {
            return;
        }

        $item->update(['archived_at' => now()]);
        $this->recordActivity($item, $actor, BoardItemActivityType::Archived);
        $this->notifications->notifyFollowers(
            $item,
            $actor,
            'Board card archived',
            $actor->getFilamentName().' archived “'.$item->title.'”.',
        );
    }

    public function restore(BoardItem $item, User $actor): void
    {
        Gate::forUser($actor)->authorize('archive', $item);

        if (! $item->isArchived()) {
            return;
        }

        $item->update(['archived_at' => null]);
        $this->recordActivity($item, $actor, BoardItemActivityType::Restored);
    }

    public function retireStage(BoardStage $stage, BoardStage $replacement, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $stage);

        if ($stage->board_id !== $replacement->board_id || $stage->is($replacement) || $replacement->archived_at !== null) {
            throw new InvalidArgumentException('Choose another active stage on the same board.');
        }

        if ($stage->board->activeStages()->count() <= 1) {
            throw new InvalidArgumentException('A board must retain at least one active stage.');
        }

        DB::transaction(function () use ($stage, $replacement, $actor): void {
            $lastPosition = BoardItem::query()
                ->where('board_stage_id', $replacement->id)
                ->whereNotNull('position')
                ->max('position');

            foreach ($stage->items()->orderBy('position')->cursor() as $item) {
                $newPosition = $lastPosition === null
                    ? DecimalPosition::forEmptyColumn()
                    : DecimalPosition::after((string) $lastPosition);
                $item->update([
                    'board_stage_id' => $replacement->id,
                    'position' => $newPosition,
                ]);
                $lastPosition = $newPosition;
                $this->recordStageChange($item, $stage, $replacement, $actor);
            }

            if ($stage->is_default) {
                $replacement->board->stages()->update(['is_default' => false]);
                $replacement->update(['is_default' => true]);
            }

            $stage->update([
                'is_default' => false,
                'archived_at' => now(),
            ]);
        });
    }

    /** @param list<int> $assigneeIds */
    private function syncAssigneesAfterAuthorization(BoardItem $item, array $assigneeIds, User $actor): void
    {

        $assigneeIds = array_values(array_unique(array_map('intval', $assigneeIds)));
        $eligibleIds = User::query()
            ->where(function (Builder $query) use ($item): void {
                $query->whereHas('boardMemberships', fn (Builder $query): Builder => $query
                    ->where('board_id', $item->board_id)
                    ->whereIn('role', [BoardMemberRole::Contributor->value, BoardMemberRole::Manager->value]))
                    ->orWhereHas('roles', fn (Builder $query): Builder => $query
                        ->whereIn('name', [Role::OWNER, Role::SUPER_ADMIN]));
            })
            ->whereIn('users.id', $assigneeIds)
            ->pluck('users.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if (count($eligibleIds) !== count($assigneeIds)) {
            throw new InvalidArgumentException('Every assignee must be a contributor or manager of this board.');
        }

        $before = $item->assignees()->pluck('users.id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
        $after = collect($eligibleIds)->sort()->values()->all();

        if ($before === $after) {
            return;
        }

        $item->assignees()->sync($after);
        User::query()->whereIn('id', $after)->get()->each(
            fn (User $user) => $this->notifications->followAutomatically($item, $user),
        );

        $this->recordActivity($item, $actor, BoardItemActivityType::AssigneesChanged, [
            'before' => $before,
            'after' => $after,
        ]);
        $this->notifications->notifyFollowers(
            $item,
            $actor,
            'Board assignments changed',
            $actor->getFilamentName().' updated the assignees for “'.$item->title.'”.',
            array_values(array_diff($after, $before)),
        );
    }

    /** @param array<string, mixed>|null $metadata */
    private function recordActivity(BoardItem $item, User $actor, BoardItemActivityType $type, ?array $metadata = null): BoardItemActivity
    {
        return $item->activities()->create([
            'actor_id' => $actor->id,
            'type' => $type,
            'metadata' => $metadata,
        ]);
    }
}
