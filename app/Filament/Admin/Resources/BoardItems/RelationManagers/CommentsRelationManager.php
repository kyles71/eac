<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BoardItems\RelationManagers;

use App\Models\BoardItem;
use App\Models\BoardItemComment;
use App\Models\User;
use App\Services\Boards\BoardItemWorkflowService;
use App\Support\BoardAttachments;
use App\Support\MediaDisks;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class CommentsRelationManager extends RelationManager
{
    protected static string $relationship = 'comments';

    protected static ?string $title = 'Discussion';

    public static function canViewForRecord(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof BoardItem && Gate::allows('view', $ownerRecord);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            RichEditor::make('body')
                ->label('Comment')
                ->toolbarButtons([
                    ['bold', 'italic', 'underline', 'strike', 'link'],
                    ['blockquote', 'codeBlock', 'bulletList', 'orderedList'],
                    ['undo', 'redo'],
                ])
                ->required()
                ->columnSpanFull(),
            SpatieMediaLibraryFileUpload::make('attachments')
                ->collection('attachments')
                ->disk(MediaDisks::private())
                ->visibility('private')
                ->multiple()
                ->preserveFilenames()
                ->downloadable()
                ->maxSize(BoardAttachments::MAX_SIZE_KILOBYTES)
                ->acceptedFileTypes(BoardAttachments::acceptedFileTypes())
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['author', 'media']))
            ->columns([
                TextColumn::make('author.full_name')
                    ->label('Author')
                    ->placeholder('Deleted user'),
                TextColumn::make('body')
                    ->label('Comment')
                    ->html()
                    ->wrap(),
                TextColumn::make('attachments')
                    ->state(fn (BoardItemComment $record): string => $record->getMedia('attachments')
                        ->map(fn (Media $media): string => sprintf(
                            '<a href="%s" class="fi-link">%s</a>',
                            e(route('admin.board-item-comments.attachments.download', [
                                'boardItemComment' => $record,
                                'media' => $media,
                            ])),
                            e($media->file_name),
                        ))
                        ->join('<br>'))
                    ->html()
                    ->placeholder('None'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('edited_at')
                    ->label('Edited')
                    ->dateTime()
                    ->placeholder('No')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at')
            ->headerActions([
                CreateAction::make()
                    ->label('Add comment')
                    ->visible(fn (): bool => Gate::allows('comment', $this->getOwnerRecord()))
                    ->mutateDataUsing(function (array $data): array {
                        $data['author_id'] = auth()->id();

                        return $data;
                    })
                    ->after(function (BoardItemComment $record): void {
                        $user = auth()->user();
                        abort_unless($user instanceof User, 403);
                        app(BoardItemWorkflowService::class)->commentCreated($record, $user);
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->mutateDataUsing(function (array $data): array {
                            $data['edited_at'] = now();

                            return $data;
                        }),
                    DeleteAction::make(),
                ]),
            ], RecordActionsPosition::BeforeCells);
    }
}
