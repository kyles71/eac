<?php

declare(strict_types=1);

namespace App\Filament\Shared\Widgets;

use App\Models\DashboardMessage;
use App\Models\User;
use App\Settings\DashboardAppearanceSettings;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;

final class MessagesFromEac extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected string $view = 'filament.shared.widgets.messages-from-eac';

    protected int|string|array $columnSpan = 1;

    /**
     * @return Collection<int, DashboardMessage>
     */
    public function messages(): Collection
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return new Collection();
        }

        return DashboardMessage::query()
            ->active()
            ->visibleTo($user)
            ->audienceOrdered()
            ->get();
    }

    public function bulletImageUrl(): ?string
    {
        return app(DashboardAppearanceSettings::class)->messagesBulletImageUrl();
    }

    public function viewAllAction(): Action
    {
        return Action::make('viewAll')
            ->label('View all')
            ->link()
            ->modalHeading('Messages From EAC')
            ->modalWidth('3xl')
            ->modalContent(fn (): View => view('filament.shared.widgets.messages-modal', [
                'messages' => $this->messages(),
                'bulletImageUrl' => $this->bulletImageUrl(),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }
}
