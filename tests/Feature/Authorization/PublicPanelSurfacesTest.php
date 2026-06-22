<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Shared\Pages\Calendar;
use App\Filament\Shared\Widgets\CalendarWidget;
use App\Filament\Shared\Widgets\MessagesFromEac;
use App\Filament\Shared\Widgets\QuickLinks;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

it('shows the admin dashboard calendar page and shared widgets without page or widget permissions', function (): void {
    Filament::setCurrentPanel('admin');

    $user = User::factory()->create();
    $user->givePermissionTo('ViewAny:Calendar');
    $this->actingAs($user);

    livewire(Dashboard::class)
        ->assertOk()
        ->assertSeeLivewire(MessagesFromEac::class)
        ->assertSeeLivewire(QuickLinks::class)
        ->assertSeeLivewire(CalendarWidget::class);

    livewire(Calendar::class)
        ->assertOk()
        ->assertSeeLivewire(CalendarWidget::class);
});
