<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BoardItems\Schemas;

use App\Enums\BoardItemPriority;
use App\Enums\BoardItemType;
use App\Enums\BoardMemberRole;
use App\Models\Board;
use App\Models\BoardItem;
use App\Models\Role;
use App\Models\User;
use App\Services\Boards\BoardItemWorkflowService;
use App\Support\BoardAttachments;
use App\Support\MediaDisks;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

final class BoardItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components(self::components());
    }

    /** @return list<Component> */
    public static function creationComponents(Board $board): array
    {
        return self::components($board);
    }

    /** @return list<Component> */
    private static function components(?Board $board = null): array
    {
        return [
            Section::make('Card')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('title')
                        ->required()
                        ->maxLength(180)
                        ->columnSpanFull(),
                    Select::make('type')
                        ->options(fn (?BoardItem $record): array => self::board($board, $record)?->itemTypeOptions() ?? [])
                        ->default(fn (): string => self::board($board)?->allowed_item_types[0] ?? BoardItemType::Task->value)
                        ->required(),
                    Select::make('priority')
                        ->options(BoardItemPriority::class)
                        ->enum(BoardItemPriority::class)
                        ->default(BoardItemPriority::Medium->value)
                        ->visible(fn (?BoardItem $record): bool => self::canManageWorkflow($board, $record))
                        ->dehydrated(fn (?BoardItem $record): bool => self::canManageWorkflow($board, $record))
                        ->required(),
                    RichEditor::make('description')
                        ->toolbarButtons([
                            ['bold', 'italic', 'underline', 'strike', 'link'],
                            ['h2', 'h3'],
                            ['blockquote', 'codeBlock', 'bulletList', 'orderedList'],
                            ['undo', 'redo'],
                        ])
                        ->columnSpanFull(),
                    DatePicker::make('due_date')
                        ->visible(fn (?BoardItem $record): bool => self::canManageWorkflow($board, $record))
                        ->dehydrated(fn (?BoardItem $record): bool => self::canManageWorkflow($board, $record)),
                    TextInput::make('related_url')
                        ->label('Related URL')
                        ->url()
                        ->maxLength(2048)
                        ->visible(fn (?BoardItem $record): bool => self::canManageWorkflow($board, $record))
                        ->dehydrated(fn (?BoardItem $record): bool => self::canManageWorkflow($board, $record)),
                    Select::make('assignees')
                        ->label('Assignees')
                        ->multiple()
                        ->preload()
                        ->searchable(['first_name', 'last_name', 'email'])
                        ->relationship(
                            name: 'assignees',
                            titleAttribute: 'first_name',
                            modifyQueryUsing: fn (Builder $query, ?BoardItem $record): Builder => self::eligibleAssigneesQuery(
                                $query,
                                self::board($board, $record),
                            ),
                        )
                        ->getOptionLabelFromRecordUsing(fn (User $record): string => $record->getFilamentName())
                        ->saveRelationshipsUsing(function (?BoardItem $record, array $state): void {
                            $user = auth()->user();

                            if ($record instanceof BoardItem && $user instanceof User) {
                                app(BoardItemWorkflowService::class)->syncAssignees(
                                    $record,
                                    array_map('intval', $state),
                                    $user,
                                );
                            }
                        })
                        ->visible(fn (?BoardItem $record): bool => self::canManageWorkflow($board, $record))
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
                ]),
        ];
    }

    private static function board(?Board $board, ?BoardItem $record = null): ?Board
    {
        return $board ?? $record?->board;
    }

    private static function canManageWorkflow(?Board $board, ?BoardItem $record): bool
    {
        return $record instanceof BoardItem
            ? Gate::allows('assign', $record)
            : ($board instanceof Board && Gate::allows('manageWorkflow', $board));
    }

    private static function eligibleAssigneesQuery(Builder $query, ?Board $board): Builder
    {
        if (! $board instanceof Board) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use ($board): void {
            $query->whereHas('boardMemberships', fn (Builder $query): Builder => $query
                ->where('board_id', $board->id)
                ->whereIn('role', [BoardMemberRole::Contributor->value, BoardMemberRole::Manager->value]))
                ->orWhereHas('roles', fn (Builder $query): Builder => $query
                    ->whereIn('name', [Role::OWNER, Role::SUPER_ADMIN]));
        });
    }
}
