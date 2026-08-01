<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\User;
use App\Services\Updates\UpdatesFeedService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

final class Updates extends Page
{
    /** @var array<string, mixed> */
    public array $feed = [];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Updates';

    protected string $view = 'filament.admin.pages.updates';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('View:Updates');
    }

    public function mount(UpdatesFeedService $service): void
    {
        $this->feed = $service->get()->toArray();
    }

    public function getSubheading(): string
    {
        return 'See what is ready to test on dev and what has recently reached production.';
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh')
                ->icon(Heroicon::ArrowPath)
                ->action(function (UpdatesFeedService $service): void {
                    $this->feed = $service->refresh()->toArray();

                    $title = match (true) {
                        $this->feed['unavailable'] === true => 'Updates unavailable',
                        $this->feed['stale'] === true => 'Using cached updates',
                        default => 'Updates refreshed',
                    };

                    $notification = Notification::make()
                        ->title($title);

                    if ($this->feed['stale'] === true || $this->feed['unavailable'] === true) {
                        $notification->warning();
                    } else {
                        $notification->success();
                    }

                    $notification->send();
                }),
        ];
    }
}
