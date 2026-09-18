<?php

declare(strict_types=1);

namespace App\Filament\User\Widgets;

use App\Models\User;
use App\Support\UserAttention;
use Filament\Widgets\Widget;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

final class UserBanners extends Widget
{
    protected static bool $isLazy = false;

    protected string $view = 'filament.user.widgets.user-banners';

    #[On(UserAttention::UPDATED_EVENT)]
    public function refreshBanners(): void {}

    /**
     * @return list<array{group: string, title: string, description: string, url: string, action: string, color: string}>
     */
    public function tasks(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        return app(UserAttention::class)->tasks($user);
    }

    /**
     * @param  list<array{group: string, title: string, description: string, url: string, action: string, color: string}>  $tasks
     */
    public function summary(array $tasks): string
    {
        $tasks = collect($tasks);

        return collect([
            'payments' => 'urgent payment',
            'forms' => 'form',
            'class_assignments' => 'class seat',
            'held_classes' => 'held seat',
            'private_lessons' => 'private lesson',
        ])->map(function (string $label, string $group) use ($tasks): ?string {
            $count = $tasks->where('group', $group)->count();

            return $count > 0 ? $count.' '.Str::plural($label, $count) : null;
        })->filter()->join(' · ');
    }
}
