<?php

declare(strict_types=1);

use App\Enums\BoardInteractionMode;
use App\Enums\BoardItemActivityType;
use App\Enums\BoardItemPriority;
use App\Enums\BoardItemType;
use App\Enums\BoardMemberRole;
use App\Enums\BoardStageKind;
use App\Enums\BoardTemplate;
use App\Models\Board;
use App\Models\BoardItem;
use App\Models\BoardMembership;
use App\Models\BoardStage;
use App\Models\User;
use App\Services\Boards\BoardItemWorkflowService;
use App\Services\Boards\BoardTemplateService;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
});

it('installs the feedback board with the product workflow', function (): void {
    $board = Board::query()->where('slug', 'portal-planning')->firstOrFail();

    expect($board->name)->toBe('Portal Planning')
        ->and($board->interaction_mode)->toBe(BoardInteractionMode::Moderated)
        ->and($board->allowed_item_types)->toBe([
            BoardItemType::Bug->value,
            BoardItemType::FeatureRequest->value,
            BoardItemType::Idea->value,
        ])
        ->and($board->activeStages()->pluck('name')->all())->toBe([
            'Future Ideas',
            'Planning',
            'Ready to Build',
            'In Progress',
            'Ready for Testing',
            'Released',
            'Not Planned',
        ])
        ->and($board->defaultStage()?->name)->toBe('Future Ideas')
        ->and($board->activeStages()->where('kind', BoardStageKind::Completed)->value('name'))->toBe('Released')
        ->and($board->activeStages()->where('kind', BoardStageKind::Cancelled)->value('name'))->toBe('Not Planned');
});

it('labels feature request cards as features', function (): void {
    expect(BoardItemType::FeatureRequest->getLabel())->toBe('Feature');
});

it('creates general and custom boards from editable presets', function (): void {
    $owner = User::factory()->isOwner()->create();
    $service = app(BoardTemplateService::class);

    $general = $service->create([
        'template' => BoardTemplate::GeneralKanban,
        'name' => 'Studio Projects',
    ], $owner);
    $custom = $service->create([
        'template' => BoardTemplate::Blank,
        'name' => 'Costume Planning',
        'interaction_mode' => BoardInteractionMode::Moderated,
        'allowed_item_types' => [BoardItemType::Idea->value, BoardItemType::Task->value],
        'custom_stages' => [
            ['name' => 'Suggestions', 'color' => 'gray', 'kind' => BoardStageKind::Active],
            ['name' => 'Approved', 'color' => 'success', 'kind' => BoardStageKind::Completed],
        ],
    ], $owner);

    expect($general->activeStages()->pluck('name')->all())->toBe(['Backlog', 'To Do', 'In Progress', 'Review', 'Done'])
        ->and($general->interaction_mode)->toBe(BoardInteractionMode::Collaborative)
        ->and($general->defaultStage()?->name)->toBe('Backlog')
        ->and($general->membershipRoleFor($owner))->toBe(BoardMemberRole::Manager)
        ->and($custom->activeStages()->pluck('name')->all())->toBe(['Suggestions', 'Approved'])
        ->and($custom->defaultStage()?->name)->toBe('Suggestions')
        ->and($custom->allowed_item_types)->toBe([BoardItemType::Idea->value, BoardItemType::Task->value]);
});

it('forces moderated contributor submissions into the intake stage without workflow metadata', function (): void {
    $board = Board::factory()->moderated()->create();
    $intake = BoardStage::factory()->for($board)->default()->create(['name' => 'Future Ideas', 'sort_order' => 10]);
    $review = BoardStage::factory()->for($board)->create(['name' => 'Planning', 'sort_order' => 20]);
    $contributor = User::factory()->isTeacher()->create();
    BoardMembership::factory()->for($board)->for($contributor)->create(['role' => BoardMemberRole::Contributor]);

    $item = app(BoardItemWorkflowService::class)->create($board, $review, $contributor, [
        'type' => BoardItemType::Bug,
        'priority' => BoardItemPriority::Urgent,
        'title' => 'Unable to open attendance',
        'description' => '<p>The page remains blank.</p>',
        'due_date' => today(),
        'related_url' => 'https://example.com/internal',
    ]);

    expect($item->board_stage_id)->toBe($intake->id)
        ->and($item->priority)->toBe(BoardItemPriority::Medium)
        ->and($item->due_date)->toBeNull()
        ->and($item->related_url)->toBeNull()
        ->and($item->activities()->where('type', BoardItemActivityType::Created)->exists())->toBeTrue()
        ->and($item->subscriptions()->where('user_id', $contributor->id)->whereNull('muted_at')->exists())->toBeTrue();
});

it('retires a stage by moving cards and preserving a default stage', function (): void {
    $board = Board::factory()->create();
    $backlog = BoardStage::factory()->for($board)->default()->create(['name' => 'Backlog', 'sort_order' => 10]);
    $todo = BoardStage::factory()->for($board)->create(['name' => 'To Do', 'sort_order' => 20]);
    $manager = User::factory()->isTeacher()->create();
    BoardMembership::factory()->for($board)->for($manager)->manager()->create();
    $item = BoardItem::factory()->for($board)->for($backlog, 'stage')->create();

    app(BoardItemWorkflowService::class)->retireStage($backlog, $todo, $manager);

    expect($backlog->refresh()->archived_at)->not->toBeNull()
        ->and($todo->refresh()->is_default)->toBeTrue()
        ->and($item->refresh()->board_stage_id)->toBe($todo->id)
        ->and($item->activities()->where('type', BoardItemActivityType::StageChanged)->exists())->toBeTrue();
});
