<?php

declare(strict_types=1);

use App\Enums\BoardItemPriority;
use App\Enums\BoardItemType;
use App\Enums\BoardMemberRole;
use App\Enums\BoardStageKind;
use App\Enums\BoardTemplate;
use App\Filament\Admin\Resources\BoardItems\BoardItemResource;
use App\Filament\Admin\Resources\BoardItems\Pages\ViewBoardItem;
use App\Filament\Admin\Resources\BoardItems\RelationManagers\CommentsRelationManager;
use App\Filament\Admin\Resources\Boards\BoardResource;
use App\Filament\Admin\Resources\Boards\Pages\BoardKanban;
use App\Filament\Admin\Resources\Boards\Pages\BoardLanding;
use App\Filament\Admin\Resources\Boards\Schemas\BoardMembershipForm;
use App\Models\Board;
use App\Models\BoardItem;
use App\Models\BoardMembership;
use App\Models\BoardStage;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Grid;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

it('uses stable board slugs and removes the old board URLs', function (): void {
    $board = Board::factory()->create(['slug' => 'production-schedule']);
    BoardStage::factory()->for($board)->default()->create();
    $member = User::factory()->isTeacher()->create();
    BoardMembership::factory()->for($board)->for($member)->viewer()->create();
    $this->actingAs($member);

    $url = BoardResource::getUrl('board', ['record' => $board]);

    expect(parse_url($url, PHP_URL_PATH))->toBe('/admin/boards/production-schedule');

    $this->get($url)->assertOk();

    expect($member->refresh()->last_viewed_board_id)->toBe($board->id);

    $this->get("/admin/boards/{$board->id}")->assertNotFound();
    $this->get("/admin/boards/{$board->id}/board")->assertNotFound();
    $this->get("/admin/boards/{$board->id}/manage")->assertNotFound();
});

it('opens the last accessible board and falls back to the newest active board', function (): void {
    $member = User::factory()->isTeacher()->create();
    $older = Board::factory()->create(['updated_at' => now()->subDay()]);
    $newer = Board::factory()->create(['updated_at' => now()]);
    $archived = Board::factory()->create(['archived_at' => now(), 'updated_at' => now()->addMinute()]);
    $inaccessible = Board::factory()->create();
    BoardMembership::factory()->for($older)->for($member)->viewer()->create();
    BoardMembership::factory()->for($newer)->for($member)->viewer()->create();
    BoardMembership::factory()->for($archived)->for($member)->viewer()->create();
    $this->actingAs($member);

    $member->forceFill(['last_viewed_board_id' => $archived->id])->save();

    livewire(BoardLanding::class)
        ->assertRedirect(BoardResource::getUrl('board', ['record' => $archived]));

    $member->forceFill(['last_viewed_board_id' => $inaccessible->id])->save();

    livewire(BoardLanding::class)
        ->assertRedirect(BoardResource::getUrl('board', ['record' => $newer]));
});

it('renders an empty workspace for a user who may create but cannot access a board', function (): void {
    $creator = User::factory()->create();
    $creator->givePermissionTo('Create:Board');
    $this->actingAs($creator);

    $component = livewire(BoardLanding::class)
        ->assertOk()
        ->assertSee('No boards are available')
        ->assertActionVisible('createBoard');

    expect($component->instance()->getBreadcrumbs())->toBe([]);
});

it('creates and validates custom boards from the workspace header', function (): void {
    $owner = User::factory()->isOwner()->create();
    $current = Board::factory()->create();
    BoardStage::factory()->for($current)->default()->create();
    $this->actingAs($owner);

    livewire(BoardKanban::class, ['record' => $current->slug])
        ->callAction('createBoard', data: [
            'template' => BoardTemplate::Blank->value,
            'name' => 'Production Schedule',
            'interaction_mode' => 'collaborative',
            'allowed_item_types' => [BoardItemType::Task->value],
            'custom_stages' => [
                [
                    'name' => 'Queued',
                    'color' => 'gray',
                    'kind' => BoardStageKind::Active->value,
                ],
            ],
        ])
        ->assertHasNoActionErrors();

    $board = Board::query()->where('name', 'Production Schedule')->firstOrFail();

    expect($board->activeStages()->pluck('name')->all())->toBe(['Queued']);

    livewire(BoardKanban::class, ['record' => $current->slug])
        ->callAction('createBoard', data: [
            'template' => BoardTemplate::Blank->value,
            'name' => 'Stage-less Board',
            'interaction_mode' => 'collaborative',
            'allowed_item_types' => [BoardItemType::Task->value],
            'custom_stages' => [],
        ])
        ->assertHasActionErrors(['custom_stages']);

    expect(Board::query()->where('name', 'Stage-less Board')->exists())->toBeFalse();
});

it('renders workspace stage controls and counts matching search results', function (): void {
    $board = Board::factory()->create();
    $backlog = BoardStage::factory()->for($board)->default()->create(['name' => 'Backlog', 'sort_order' => 10]);
    $manager = User::factory()->isTeacher()->create();
    BoardMembership::factory()->for($board)->for($manager)->manager()->create();
    $matchingItem = BoardItem::factory()->for($board)->for($backlog, 'stage')->create(['title' => 'Matching card']);
    BoardItem::factory()->for($board)->for($backlog, 'stage')->create(['title' => 'Different card']);
    $this->actingAs($manager);

    $component = livewire(BoardKanban::class, ['record' => $board->slug])
        ->assertOk()
        ->assertSee('flowforge.board.'.$board->id.'.collapsed-stages', escape: false)
        ->assertSee('data-stage-sortable-item', escape: false)
        ->assertSee('toggleStage(stageId)', escape: false)
        ->assertDontSee('@js((string) $columnId)', escape: false)
        ->assertSee('$dispatch(\'close-stage-menus\')', escape: false)
        ->assertSee('x-on:close-stage-menus.window="close()"', escape: false)
        ->assertSee('writing-mode: vertical-rl;', escape: false)
        ->assertDontSee('transform: rotate(180deg);', escape: false)
        ->assertSee('w-full justify-center', escape: false)
        ->assertSee('aria-label="Priority: Medium"', escape: false)
        ->assertSee('aria-label="Type: Task"', escape: false)
        ->assertSee('aria-label="0 comments"', escape: false)
        ->assertActionVisible('addStage')
        ->set('tableSearch', 'Matching');

    expect($component->instance()->getBoard()->getBatchedBoardRecordCounts())
        ->toMatchArray([(string) $backlog->id => 1]);

    expect($component->instance()->getBreadcrumbs())->toBe([]);

    $recordActions = $component->instance()->getBoard()->getRecordActions();

    expect($recordActions)->toHaveCount(1)
        ->and($recordActions[0])->toBeInstanceOf(Action::class)
        ->and($recordActions[0])->not->toBeInstanceOf(ActionGroup::class);

    $cardRows = $component->instance()->getBoard()->getCardSchema($matchingItem)?->getComponents() ?? [];
    $detailEntries = $cardRows[0]->getChildSchema()->getComponents();

    expect($cardRows)->toHaveCount(1)
        ->and($cardRows[0])->toBeInstanceOf(Grid::class)
        ->and($cardRows[0]->getColumns('lg'))->toBe(2)
        ->and(collect($detailEntries)->map->getName()->all())->toBe(['assignees.full_name', 'due_date']);
});

it('switches only to accessible active or archived boards', function (): void {
    $member = User::factory()->isTeacher()->create();
    $current = Board::factory()->create();
    $archived = Board::factory()->create(['archived_at' => now()]);
    $hidden = Board::factory()->create();
    BoardStage::factory()->for($current)->default()->create();
    BoardStage::factory()->for($archived)->default()->create();
    BoardMembership::factory()->for($current)->for($member)->viewer()->create();
    BoardMembership::factory()->for($archived)->for($member)->viewer()->create();
    $this->actingAs($member);

    $component = livewire(BoardKanban::class, ['record' => $current->slug])
        ->assertActionVisible('switchBoard');

    $switchBoardAction = $component->instance()->getAction('switchBoard');

    expect($switchBoardAction?->isModalHeaderSticky())->toBeFalse()
        ->and($switchBoardAction?->isModalFooterSticky())->toBeFalse();

    $component
        ->callAction('switchBoard', data: ['board_id' => $archived->id])
        ->assertRedirect(BoardResource::getUrl('board', ['record' => $archived]));

    $this->get(BoardResource::getUrl('board', ['record' => $hidden]))->assertNotFound();
});

it('hides the board switcher when only one board is accessible', function (): void {
    $board = Board::factory()->create();
    BoardStage::factory()->for($board)->default()->create();
    $member = User::factory()->isTeacher()->create();
    BoardMembership::factory()->for($board)->for($member)->viewer()->create();
    $this->actingAs($member);

    livewire(BoardKanban::class, ['record' => $board->slug])
        ->assertActionHidden('switchBoard');
});

it('updates board settings without changing the slug', function (): void {
    $board = Board::factory()->create(['slug' => 'stable-board']);
    BoardStage::factory()->for($board)->default()->create();
    $manager = User::factory()->isTeacher()->create();
    BoardMembership::factory()->for($board)->for($manager)->manager()->create();
    $this->actingAs($manager);

    livewire(BoardKanban::class, ['record' => $board->slug])
        ->callAction('boardSettings', data: [
            'name' => 'Renamed Board',
            'description' => 'A new description.',
            'interaction_mode' => 'moderated',
            'allowed_item_types' => [BoardItemType::Bug->value],
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($board->refresh()->name)->toBe('Renamed Board')
        ->and($board->slug)->toBe('stable-board')
        ->and($board->description)->toBe('A new description.');
});

it('lets board managers archive and restore a read-only board from settings', function (): void {
    $board = Board::factory()->create();
    $stage = BoardStage::factory()->for($board)->default()->create();
    $manager = User::factory()->isTeacher()->create();
    BoardMembership::factory()->for($board)->for($manager)->manager()->create();
    $this->actingAs($manager);

    livewire(BoardKanban::class, ['record' => $board->slug])
        ->callAction(['boardSettings', 'archiveBoard']);

    expect($board->refresh()->isArchived())->toBeTrue();

    livewire(BoardKanban::class, ['record' => $board->slug])
        ->assertSee('Archived — read only.')
        ->assertActionHidden('manageMembers')
        ->assertActionHidden('addStage')
        ->assertActionHidden(TestAction::make('addCard')->arguments(['column' => (string) $stage->id]))
        ->callAction(['boardSettings', 'restoreBoard']);

    expect($board->refresh()->isArchived())->toBeFalse();
});

it('saves membership changes atomically and removes ineligible assignments', function (): void {
    $board = Board::factory()->create();
    $stage = BoardStage::factory()->for($board)->default()->create();
    $manager = User::factory()->isTeacher()->create();
    $secondManager = User::factory()->isTeacher()->create();
    $contributor = User::factory()->isTeacher()->create();
    $viewer = User::factory()->isTeacher()->create();
    BoardMembership::factory()->for($board)->for($manager)->manager()->create();
    BoardMembership::factory()->for($board)->for($secondManager)->manager()->create();
    BoardMembership::factory()->for($board)->for($contributor)->create();
    $item = BoardItem::factory()->for($board)->for($stage, 'stage')->create();
    $item->assignees()->attach($contributor);
    $this->actingAs($manager);

    $roleHeader = BoardMembershipForm::make($board)->getTableColumns()[1]->getLabel()->toHtml();

    expect($roleHeader)
        ->toContain('Viewer: view the board and cards.')
        ->toContain('Contributor: submit and comment;')
        ->toContain('Manager: manage the board, stages, cards, and members.')
        ->toContain('x-tooltip')
        ->toMatch('/Role.*fi-fo-table-repeater-header-required-mark.*Board role descriptions/s');

    livewire(BoardKanban::class, ['record' => $board->slug])
        ->callAction('manageMembers', data: [
            'memberships' => [
                ['user_id' => $manager->id, 'role' => BoardMemberRole::Manager->value],
                ['user_id' => $secondManager->id, 'role' => BoardMemberRole::Manager->value],
                ['user_id' => $contributor->id, 'role' => BoardMemberRole::Viewer->value],
                ['user_id' => $viewer->id, 'role' => BoardMemberRole::Viewer->value],
            ],
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    assertDatabaseHas(BoardMembership::class, [
        'board_id' => $board->id,
        'user_id' => $viewer->id,
        'role' => BoardMemberRole::Viewer->value,
    ]);
    expect($item->assignees()->whereKey($contributor->id)->exists())->toBeFalse();
});

it('prevents removing or downgrading the final board manager', function (): void {
    $board = Board::factory()->create();
    BoardStage::factory()->for($board)->default()->create();
    $manager = User::factory()->isTeacher()->create();
    $invitee = User::factory()->isTeacher()->create();
    BoardMembership::factory()->for($board)->for($manager)->manager()->create();
    $this->actingAs($manager);

    livewire(BoardKanban::class, ['record' => $board->slug])
        ->callAction('manageMembers', data: [
            'memberships' => [
                ['user_id' => $manager->id, 'role' => BoardMemberRole::Viewer->value],
                ['user_id' => $invitee->id, 'role' => BoardMemberRole::Viewer->value],
            ],
        ])
        ->assertHasActionErrors(['memberships']);

    assertDatabaseMissing(BoardMembership::class, [
        'board_id' => $board->id,
        'user_id' => $invitee->id,
    ]);
    expect($board->membershipRoleFor($manager))->toBe(BoardMemberRole::Manager);
});

it('creates edits reorders and retires stages from the board', function (): void {
    $board = Board::factory()->create();
    $backlog = BoardStage::factory()->for($board)->default()->create(['name' => 'Backlog', 'sort_order' => 10]);
    $review = BoardStage::factory()->for($board)->create(['name' => 'Review', 'sort_order' => 20]);
    $manager = User::factory()->isTeacher()->create();
    BoardMembership::factory()->for($board)->for($manager)->manager()->create();
    $item = BoardItem::factory()->for($board)->for($backlog, 'stage')->create();
    $this->actingAs($manager);

    $component = livewire(BoardKanban::class, ['record' => $board->slug])
        ->assertDontSee('Default submission stage')
        ->callAction('addStage', data: [
            'name' => 'Done',
            'color' => 'success',
            'kind' => BoardStageKind::Completed->value,
        ])
        ->assertHasNoActionErrors();

    $done = $board->activeStages()->where('name', 'Done')->firstOrFail();

    $component
        ->callAction(TestAction::make('editStage')->arguments(['column' => (string) $review->id]), data: [
            'name' => 'Quality Review',
            'color' => 'warning',
            'kind' => BoardStageKind::Active->value,
        ])
        ->assertHasNoActionErrors()
        ->call('reorderStages', [$done->id, $review->id, $backlog->id]);

    expect($board->activeStages()->pluck('id')->all())->toBe([$done->id, $review->id, $backlog->id])
        ->and($review->refresh()->name)->toBe('Quality Review')
        ->and($review->is_default)->toBeFalse()
        ->and($backlog->refresh()->is_default)->toBeTrue();

    $component
        ->callAction(
            TestAction::make('retireStage')->arguments(['column' => (string) $backlog->id]),
            data: ['replacement_stage_id' => $review->id],
        )
        ->assertHasNoActionErrors();

    expect($backlog->refresh()->archived_at)->not->toBeNull()
        ->and($item->refresh()->board_stage_id)->toBe($review->id);
});

it('rejects stage orders containing foreign stages', function (): void {
    $board = Board::factory()->create();
    $stage = BoardStage::factory()->for($board)->default()->create();
    $foreignStage = BoardStage::factory()->default()->create();
    $manager = User::factory()->isTeacher()->create();
    BoardMembership::factory()->for($board)->for($manager)->manager()->create();
    $this->actingAs($manager);

    livewire(BoardKanban::class, ['record' => $board->slug])
        ->call('reorderStages', [$stage->id, $foreignStage->id])
        ->assertHasErrors(['stageOrder']);
});

it('moves cards for collaborative contributors and records the activity', function (): void {
    $board = Board::factory()->create();
    $backlog = BoardStage::factory()->for($board)->default()->create(['name' => 'Backlog', 'sort_order' => 10]);
    $done = BoardStage::factory()->for($board)->create(['name' => 'Done', 'sort_order' => 20]);
    $contributor = User::factory()->isTeacher()->create();
    BoardMembership::factory()->for($board)->for($contributor)->create();
    $item = BoardItem::factory()->for($board)->for($backlog, 'stage')->create();
    $this->actingAs($contributor);

    livewire(BoardKanban::class, ['record' => $board->slug])
        ->assertOk()
        ->call('moveCard', (string) $item->id, (string) $done->id);

    expect($item->refresh()->board_stage_id)->toBe($done->id)
        ->and($item->activities()->where('type', 'stage_changed')->exists())->toBeTrue();
});

it('creates moderated submissions through the board toolbar', function (): void {
    $board = Board::factory()->moderated()->create();
    $intake = BoardStage::factory()->for($board)->default()->create(['name' => 'Future Ideas']);
    $contributor = User::factory()->isTeacher()->create();
    BoardMembership::factory()->for($board)->for($contributor)->create();
    $this->actingAs($contributor);

    $component = livewire(BoardKanban::class, ['record' => $board->slug])
        ->assertActionVisible('submit')
        ->assertActionHidden(TestAction::make('addCard')->arguments(['column' => (string) $intake->id]))
        ->callAction('submit', data: [
            'type' => BoardItemType::FeatureRequest->value,
            'title' => 'Add a calendar view',
            'description' => '<p>A weekly layout would help.</p>',
        ])
        ->assertHasNoActionErrors()
        ->assertNoRedirect()
        ->assertSee('Add a calendar view');

    $item = BoardItem::query()->where('title', 'Add a calendar view')->firstOrFail();

    expect($item->board_id)->toBe($board->id)
        ->and($item->board_stage_id)->toBe($intake->id)
        ->and($item->created_by_user_id)->toBe($contributor->id)
        ->and($item->priority)->toBe(BoardItemPriority::Medium)
        ->and($component->instance()->getBoard()->getBatchedBoardRecordCounts())
        ->toMatchArray([(string) $intake->id => 1]);
});

it('lets contributors hold discussions on a card', function (): void {
    $board = Board::factory()->moderated()->create();
    $stage = BoardStage::factory()->for($board)->default()->create();
    $contributor = User::factory()->isTeacher()->create();
    BoardMembership::factory()->for($board)->for($contributor)->create();
    $item = BoardItem::factory()->for($board)->for($stage, 'stage')->create();
    $this->actingAs($contributor);

    livewire(CommentsRelationManager::class, [
        'ownerRecord' => $item,
        'pageClass' => ViewBoardItem::class,
    ])
        ->callAction(TestAction::make('create')->table(), data: [
            'body' => '<p>This would solve our workflow problem.</p>',
        ])
        ->assertHasNoActionErrors();

    assertDatabaseHas('board_item_comments', [
        'board_item_id' => $item->id,
        'author_id' => $contributor->id,
        'body' => '<p>This would solve our workflow problem.</p>',
    ]);
});

it('denies moderated contributor drag and cross-board item pages', function (): void {
    $board = Board::factory()->moderated()->create();
    $stage = BoardStage::factory()->for($board)->default()->create();
    $otherBoard = Board::factory()->create();
    $otherStage = BoardStage::factory()->for($otherBoard)->default()->create();
    $contributor = User::factory()->isTeacher()->create();
    BoardMembership::factory()->for($board)->for($contributor)->create();
    $item = BoardItem::factory()->for($board)->for($stage, 'stage')->for($contributor, 'creator')->create();
    $otherItem = BoardItem::factory()->for($otherBoard)->for($otherStage, 'stage')->create();
    $this->actingAs($contributor);

    livewire(BoardKanban::class, ['record' => $board->slug])
        ->call('moveCard', (string) $item->id, (string) $stage->id)
        ->assertForbidden();

    $this->get(BoardItemResource::getUrl('view', ['record' => $otherItem]))
        ->assertNotFound();
});

it('renders the dedicated item details and canonical board link', function (): void {
    $owner = User::factory()->isOwner()->create();
    $item = BoardItem::factory()->create(['description' => '<p>Rich description</p>']);
    $this->actingAs($owner);

    $component = livewire(ViewBoardItem::class, ['record' => $item->id])
        ->assertOk()
        ->assertSee('Rich description')
        ->assertSee(BoardResource::getUrl('board', ['record' => $item->board]), escape: false);

    expect($component->instance()->getBreadcrumbs())->toBe([]);
});
