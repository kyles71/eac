<?php

declare(strict_types=1);

use App\Enums\BoardItemType;
use App\Models\Board;
use App\Models\BoardItemComment;
use App\Models\BoardMembership;
use App\Models\BoardStage;
use App\Models\User;
use App\Notifications\BoardItemNotification;
use App\Services\Boards\BoardItemWorkflowService;
use App\Services\Boards\BoardNotificationService;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
});

it('notifies managers of submissions and followers of discussion', function (): void {
    $board = Board::factory()->moderated()->create();
    $stage = BoardStage::factory()->for($board)->default()->create();
    $manager = User::factory()->isTeacher()->create();
    $creator = User::factory()->isTeacher()->create();
    $commenter = User::factory()->isTeacher()->create();
    BoardMembership::factory()->for($board)->for($manager)->manager()->create();
    BoardMembership::factory()->for($board)->for($creator)->create();
    BoardMembership::factory()->for($board)->for($commenter)->create();

    $workflow = app(BoardItemWorkflowService::class);
    $item = $workflow->create($board, $stage, $creator, [
        'type' => BoardItemType::Idea,
        'title' => 'Show attendance trends',
    ]);

    Notification::assertSentTo($manager, BoardItemNotification::class);
    Notification::assertNotSentTo($creator, BoardItemNotification::class);

    Notification::fake();
    $comment = BoardItemComment::factory()->for($item, 'item')->for($commenter, 'author')->create();
    $workflow->commentCreated($comment, $commenter);

    Notification::assertSentTo($creator, BoardItemNotification::class);
    Notification::assertNotSentTo($commenter, BoardItemNotification::class);
    expect($item->subscriptions()->where('user_id', $commenter->id)->whereNull('muted_at')->exists())->toBeTrue();
});

it('respects explicit unwatching during later automatic follow events', function (): void {
    $item = App\Models\BoardItem::factory()->create();
    $user = User::factory()->create();
    $service = app(BoardNotificationService::class);

    $service->watch($item, $user);
    $service->unwatch($item, $user);
    $service->followAutomatically($item, $user);

    expect($item->subscriptions()->where('user_id', $user->id)->whereNotNull('muted_at')->exists())->toBeTrue();
});
