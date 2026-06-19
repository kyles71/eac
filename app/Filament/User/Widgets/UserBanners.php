<?php

declare(strict_types=1);

namespace App\Filament\User\Widgets;

use App\Filament\User\Pages\MyEnrollments;
use App\Filament\User\Resources\FormUsers\Pages\ListFormUsers;
use App\Models\FormUser;
use App\Models\User;
use App\Support\UserAttention;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;

final class UserBanners extends Widget
{
    protected static bool $isLazy = false;

    protected string $view = 'filament.user.widgets.user-banners';

    #[On(UserAttention::UPDATED_EVENT)]
    public function refreshBanners(): void {}

    public function enrollmentCount(): int
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return 0;
        }

        return app(UserAttention::class)->openEnrollmentCount($user);
    }

    /**
     * @return Collection<int, FormUser>
     */
    public function pendingForms(): Collection
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return collect();
        }

        return app(UserAttention::class)->pendingForms($user);
    }

    public function enrollmentsUrl(): string
    {
        return MyEnrollments::getUrl();
    }

    public function formsUrl(): string
    {
        return ListFormUsers::getUrl();
    }
}
