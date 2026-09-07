<?php

declare(strict_types=1);

use App\Enums\BoardInteractionMode;
use App\Enums\BoardMemberRole;
use App\Models\Board;
use App\Models\BoardItem;
use App\Models\BoardMembership;
use App\Models\BoardStage;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('enforces board roles in moderated and collaborative modes', function (): void {
    $moderated = Board::factory()->moderated()->create();
    $stage = BoardStage::factory()->for($moderated)->default()->create();
    $viewer = User::factory()->isTeacher()->create();
    $contributor = User::factory()->isTeacher()->create();
    $manager = User::factory()->isTeacher()->create();
    BoardMembership::factory()->for($moderated)->for($viewer)->viewer()->create();
    BoardMembership::factory()->for($moderated)->for($contributor)->create(['role' => BoardMemberRole::Contributor]);
    BoardMembership::factory()->for($moderated)->for($manager)->manager()->create();
    $ownItem = BoardItem::factory()->for($moderated)->for($stage, 'stage')->for($contributor, 'creator')->create();
    $otherItem = BoardItem::factory()->for($moderated)->for($stage, 'stage')->create();

    expect(Gate::forUser($viewer)->allows('view', $moderated))->toBeTrue()
        ->and(Gate::forUser($viewer)->allows('createItem', $moderated))->toBeFalse()
        ->and(Gate::forUser($contributor)->allows('createItem', $moderated))->toBeTrue()
        ->and(Gate::forUser($contributor)->allows('manageMembers', $moderated))->toBeFalse()
        ->and(Gate::forUser($contributor)->allows('update', $ownItem))->toBeTrue()
        ->and(Gate::forUser($contributor)->allows('update', $otherItem))->toBeFalse()
        ->and(Gate::forUser($contributor)->allows('move', $ownItem))->toBeFalse()
        ->and(Gate::forUser($manager)->allows('move', $otherItem))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('manageMembers', $moderated))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('archive', $moderated))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('create', BoardMembership::class))->toBeFalse();

    $moderated->update(['interaction_mode' => BoardInteractionMode::Collaborative]);
    $otherItem->unsetRelation('board');

    expect(Gate::forUser($contributor)->allows('update', $otherItem))->toBeTrue()
        ->and(Gate::forUser($contributor)->allows('move', $otherItem))->toBeTrue()
        ->and(Gate::forUser($contributor)->allows('assign', $otherItem))->toBeTrue();
});

it('isolates records by board while allowing owner and super admin overrides', function (): void {
    $firstBoard = Board::factory()->create();
    $secondBoard = Board::factory()->create();
    $member = User::factory()->isTeacher()->create();
    $owner = User::factory()->isOwner()->create();
    $outsider = User::factory()->isTeacher()->create();
    BoardMembership::factory()->for($firstBoard)->for($member)->create();

    expect(Board::query()->accessibleTo($member)->pluck('id')->all())->toBe([$firstBoard->id])
        ->and(Gate::forUser($member)->allows('view', $secondBoard))->toBeFalse()
        ->and(Gate::forUser($outsider)->allows('view', $firstBoard))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('view', $firstBoard))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('create', Board::class))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('manageMembers', $firstBoard))->toBeTrue();
});

it('makes archived boards and cards read only', function (): void {
    $board = Board::factory()->create();
    $stage = BoardStage::factory()->for($board)->default()->create();
    $manager = User::factory()->isTeacher()->create();
    BoardMembership::factory()->for($board)->for($manager)->manager()->create();
    $item = BoardItem::factory()->for($board)->for($stage, 'stage')->create();

    $board->update(['archived_at' => now()]);

    expect(Gate::forUser($manager)->allows('view', $board))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('update', $board))->toBeFalse()
        ->and(Gate::forUser($manager)->allows('manageMembers', $board))->toBeFalse()
        ->and(Gate::forUser($manager)->allows('restore', $board))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('move', $item))->toBeFalse()
        ->and(Gate::forUser($manager)->allows('comment', $item))->toBeFalse();
});

it('scopes stage management to the board a manager controls', function (): void {
    $managedBoard = Board::factory()->create();
    $otherBoard = Board::factory()->create();
    $managedStage = BoardStage::factory()->for($managedBoard)->default()->create();
    $otherStage = BoardStage::factory()->for($otherBoard)->default()->create();
    $manager = User::factory()->isTeacher()->create();
    BoardMembership::factory()->for($managedBoard)->for($manager)->manager()->create();

    expect(Gate::forUser($manager)->allows('update', $managedStage))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('update', $otherStage))->toBeFalse()
        ->and(Gate::forUser($manager)->allows('create', BoardStage::class))->toBeFalse();
});
