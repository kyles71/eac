<?php

declare(strict_types=1);

namespace App\Filament\User\Pages;

use App\Filament\Shared\Schemas\SentEmailPreviewSchema;
use App\Models\User;
use App\Services\Mail\UserVisibleSentEmailsService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Kyle\FilamentMailManager\Models\ManagedSentEmail;
use RuntimeException;

final class Messages extends TablePage
{
    protected static ?string $title = 'Email History';

    protected static ?string $slug = 'messages';

    protected static ?string $navigationLabel = 'Messages';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?int $navigationSort = 6;

    protected ?string $heading = 'Email History';

    protected ?string $subheading = 'Messages sent to your account email.';

    protected function makeTable(): Table
    {
        return $this->makeBaseTable()
            ->query(app(UserVisibleSentEmailsService::class)->query($this->user()))
            ->columns([
                TextColumn::make('sent_at')
                    ->label('Sent')
                    ->dateTime('M j, Y g:i A', timezone: (string) config('app.display_timezone', config('app.timezone')))
                    ->sortable(),
                TextColumn::make('subject')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->limit(80),
            ])
            ->recordAction('view')
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::OutlinedEye)
                    ->modal()
                    ->modalHeading(fn (ManagedSentEmail $record): string => $record->subject)
                    ->schema(SentEmailPreviewSchema::schema())
                    ->modalWidth(Width::FiveExtraLarge)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ])
            ->defaultSort('sent_at', 'desc')
            ->emptyStateHeading('No email history')
            ->emptyStateDescription('Messages sent to your account email will appear here.')
            ->emptyStateIcon(Heroicon::OutlinedEnvelope);
    }

    private function user(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new RuntimeException('Email history is only available to authenticated users.');
        }

        return $user;
    }
}
