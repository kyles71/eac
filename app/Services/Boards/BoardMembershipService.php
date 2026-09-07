<?php

declare(strict_types=1);

namespace App\Services\Boards;

use App\Enums\BoardMemberRole;
use App\Models\Board;
use App\Models\BoardMembership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class BoardMembershipService
{
    public function __construct(private readonly BoardItemWorkflowService $workflow) {}

    /** @return array<int, string> */
    public function eligibleUserOptions(Board $board): array
    {
        return $this->eligibleUsersQuery($board)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->mapWithKeys(fn (User $user): array => [$user->id => $user->getFilamentName()])
            ->all();
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $memberships
     */
    public function sync(Board $board, User $actor, array $memberships): void
    {
        Gate::forUser($actor)->authorize('manageMembers', $board);

        $normalized = $this->normalize($memberships);
        $userIds = array_keys($normalized);

        if (! collect($normalized)->contains(BoardMemberRole::Manager)) {
            throw ValidationException::withMessages([
                'memberships' => 'A board must retain at least one Manager.',
            ]);
        }

        $existingMemberships = $board->memberships()->get()->keyBy('user_id');
        $newUserIds = array_values(array_diff($userIds, $existingMemberships->keys()->map(fn (mixed $id): int => (int) $id)->all()));

        if ($newUserIds !== [] && $this->eligibleUsersQuery($board)->whereKey($newUserIds)->count() !== count($newUserIds)) {
            throw ValidationException::withMessages([
                'memberships' => 'One or more selected users are not eligible to join this board.',
            ]);
        }

        DB::transaction(function () use ($board, $actor, $normalized, $userIds, $existingMemberships): void {
            /** @var BoardMembership $membership */
            foreach ($existingMemberships as $membership) {
                $newRole = $normalized[$membership->user_id] ?? null;

                if ($membership->role->canContribute() && ! $newRole?->canContribute()) {
                    $this->workflow->removeMemberAssignments($membership, $actor);
                }
            }

            if ($userIds === []) {
                $board->memberships()->delete();
            } else {
                $board->memberships()->whereNotIn('user_id', $userIds)->delete();
            }

            foreach ($normalized as $userId => $role) {
                $board->memberships()->updateOrCreate(
                    ['user_id' => $userId],
                    ['role' => $role],
                );
            }
        });

        $board->unsetRelation('memberships');
        $board->unsetRelation('members');
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $memberships
     * @return array<int, BoardMemberRole>
     */
    private function normalize(array $memberships): array
    {
        $normalized = [];

        foreach ($memberships as $membership) {
            $userId = filter_var($membership['user_id'] ?? null, FILTER_VALIDATE_INT);
            $roleValue = $membership['role'] ?? null;
            $role = $roleValue instanceof BoardMemberRole
                ? $roleValue
                : BoardMemberRole::tryFrom((string) $roleValue);

            if ($userId === false || $userId < 1 || $role === null) {
                throw ValidationException::withMessages([
                    'memberships' => 'Every member must have a valid user and role.',
                ]);
            }

            if (array_key_exists($userId, $normalized)) {
                throw ValidationException::withMessages([
                    'memberships' => 'Each user may only be added to a board once.',
                ]);
            }

            $normalized[$userId] = $role;
        }

        return $normalized;
    }

    /** @return Builder<User> */
    private function eligibleUsersQuery(Board $board): Builder
    {
        return User::query()->where(function (Builder $query) use ($board): void {
            $query->whereHas('roles', fn (Builder $query): Builder => $query
                ->whereIn('name', [Role::TEACHER, Role::OWNER, Role::SUPER_ADMIN]))
                ->orWhereHas('permissions')
                ->orWhereHas('boardMemberships', fn (Builder $query): Builder => $query
                    ->where('board_id', $board->id));
        });
    }
}
