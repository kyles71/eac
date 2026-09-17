<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Enums\TextMessageStatus;
use App\Models\TextMessageBatch;
use App\Models\User;
use App\Support\Filament\AdminNavigation;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

final class TextMessages extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigation::Tools;

    protected static ?int $navigationSort = AdminNavigation::ToolsTextMessages;

    protected static ?string $title = 'Text Messages';

    protected string $view = 'filament.admin.pages.text-messages';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('View:TextMessageHistory');
    }

    public function getSubheading(): string
    {
        return 'Accepted means the provider accepted the request. Delivery to the phone is not tracked here. Review unknown results in the provider before resending.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => TextMessageBatch::query()
                ->when(! self::canAccess(), fn (Builder $query): Builder => $query->whereRaw('0 = 1'))
                ->withCount([
                    'recipients',
                    'recipients as accepted_count' => fn (Builder $query): Builder => $query->where('status', TextMessageStatus::Accepted),
                    'recipients as failed_count' => fn (Builder $query): Builder => $query->where('status', TextMessageStatus::Failed),
                    'recipients as unknown_count' => fn (Builder $query): Builder => $query->where('status', TextMessageStatus::Unknown),
                    'recipients as pending_count' => fn (Builder $query): Builder => $query->whereIn('status', [TextMessageStatus::Pending, TextMessageStatus::Sending]),
                    'recipients as skipped_count' => fn (Builder $query): Builder => $query->where('status', TextMessageStatus::Skipped),
                ]))
            ->columns([
                TextColumn::make('created_at')->label('Sent at')->dateTime()->sortable(),
                TextColumn::make('author_name')->label('Sender')->searchable(),
                TextColumn::make('body')->label('Message')->limit(80)->wrap()->searchable(),
                TextColumn::make('recipients_count')->label('Phones'),
                TextColumn::make('pending_count')->label('Pending'),
                TextColumn::make('accepted_count')->label('Accepted'),
                TextColumn::make('failed_count')->label('Failed'),
                TextColumn::make('unknown_count')->label('Unknown'),
                TextColumn::make('skipped_count')->label('Skipped'),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('viewTextMessage')->label('View Send')->icon(Heroicon::OutlinedEye)
                        ->authorize('View:TextMessageHistory')
                        ->modalHeading('Text Message Send')
                        ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                        ->modalWidth('5xl')
                        ->modalContent(fn (TextMessageBatch $record): View => view('filament.admin.pages.partials.text-message-history', [
                            'batch' => $record->load('recipients'),
                        ])),
                ]),
            ])
            ->defaultSort('id', 'desc')
            ->poll('5s');
    }
}
