<?php

declare(strict_types=1);

use App\Enums\BoardMemberRole;
use App\Models\Board;
use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\BoardMembership;
use App\Models\BoardStage;
use App\Models\User;
use App\Support\MediaDisks;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake(MediaDisks::private());
});

it('authorizes private card and comment attachment downloads through board membership', function (): void {
    $board = Board::factory()->create();
    $stage = BoardStage::factory()->for($board)->default()->create();
    $member = User::factory()->isTeacher()->create();
    $outsider = User::factory()->isTeacher()->create();
    BoardMembership::factory()->for($board)->for($member)->create(['role' => BoardMemberRole::Viewer]);
    $item = BoardItem::factory()->for($board)->for($stage, 'stage')->create();
    $comment = BoardItemComment::factory()->for($item, 'item')->create();
    $itemMedia = $item->addMedia(UploadedFile::fake()->image('bug.png'))
        ->toMediaCollection('attachments', MediaDisks::private());
    $commentMedia = $comment->addMedia(UploadedFile::fake()->create('details.pdf', 20, 'application/pdf'))
        ->toMediaCollection('attachments', MediaDisks::private());

    $this->actingAs($member)
        ->get(route('admin.board-items.attachments.download', ['boardItem' => $item, 'media' => $itemMedia]))
        ->assertOk()
        ->assertDownload('bug.png');
    $this->actingAs($member)
        ->get(route('admin.board-item-comments.attachments.download', ['boardItemComment' => $comment, 'media' => $commentMedia]))
        ->assertOk()
        ->assertDownload('details.pdf');
    $this->actingAs($outsider)
        ->get(route('admin.board-items.attachments.download', ['boardItem' => $item, 'media' => $itemMedia]))
        ->assertForbidden();

    expect($itemMedia->disk)->toBe(MediaDisks::private())
        ->and($commentMedia->disk)->toBe(MediaDisks::private());
});

it('does not serve media attached to another card through an authorized card URL', function (): void {
    $owner = User::factory()->isOwner()->create();
    $first = BoardItem::factory()->create();
    $second = BoardItem::factory()->create();
    $media = $second->addMedia(UploadedFile::fake()->image('other.png'))
        ->toMediaCollection('attachments', MediaDisks::private());

    $this->actingAs($owner)
        ->get(route('admin.board-items.attachments.download', ['boardItem' => $first, 'media' => $media]))
        ->assertNotFound();
});
