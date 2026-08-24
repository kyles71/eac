<?php

declare(strict_types=1);

namespace App\Filament\User\Pages;

use App\Actions\CourseHolds\AddCourseHoldToCart;
use App\Models\CourseHold;
use App\Models\CourseHoldSeat;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

final class HeldClasses extends Page
{
    protected static ?string $title = 'Held Classes';

    protected static ?string $slug = 'held-classes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static bool $shouldRegisterNavigation = false;

    public function content(Schema $schema): Schema
    {
        $holds = $this->holds();

        if ($holds->isEmpty()) {
            return $schema->components([
                Section::make('No active class holds')
                    ->description('You do not currently have any unpurchased class seats on hold.')
                    ->schema([
                        Actions::make([
                            Action::make('browseStore')
                                ->label('Browse Store')
                                ->icon(Heroicon::OutlinedShoppingBag)
                                ->url(Store::getUrl()),
                        ]),
                    ]),
            ]);
        }

        return $schema->components(
            $holds->map(fn (CourseHold $hold): Section => $this->holdSection($hold))->all(),
        );
    }

    /** @return Collection<int, CourseHold> */
    private function holds(): Collection
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return new Collection;
        }

        return CourseHold::query()
            ->where('user_id', $user->id)
            ->current()
            ->with(['seats.course', 'seats.enrollment'])
            ->orderBy('expires_at')
            ->get();
    }

    private function holdSection(CourseHold $hold): Section
    {
        $availableSeats = $hold->seats
            ->filter(fn (CourseHoldSeat $seat): bool => $seat->released_at === null
                && $seat->claimed_order_item_id === null
                && $seat->enrollment === null)
            ->groupBy('course_id');

        $components = [];

        foreach ($availableSeats as $courseId => $seats) {
            /** @var CourseHoldSeat $firstSeat */
            $firstSeat = $seats->first();

            $components[] = TextEntry::make("course_{$hold->id}_{$courseId}")
                ->label($firstSeat->course->name)
                ->state($seats->count().' '.str('seat')->plural($seats->count()).' at '.format_money($firstSeat->locked_unit_price).' each')
                ->hintAction(
                    Action::make("add_course_{$hold->id}_{$courseId}")
                        ->label('Add to Cart')
                        ->icon(Heroicon::OutlinedShoppingCart)
                        ->action(fn () => $this->addToCart($hold, [(int) $courseId => $seats->count()])),
                );
        }

        $components[] = Actions::make([
            Action::make("add_all_{$hold->id}")
                ->label('Add All Held Seats to Cart')
                ->icon(Heroicon::OutlinedShoppingCart)
                ->action(fn () => $this->addToCart($hold)),
        ])->fullWidth();

        return Section::make("Class Hold #{$hold->id}")
            ->description('Held until '.$hold->expires_at
                ->timezone((string) config('app.display_timezone', config('app.timezone')))
                ->format('F j, Y \a\t g:i A'))
            ->schema($components)
            ->columns(1)
            ->columnSpanFull();
    }

    /** @param array<int, int> $quantitiesByCourseId */
    private function addToCart(CourseHold $hold, array $quantitiesByCourseId = []): void
    {
        try {
            /** @var User $user */
            $user = auth()->user();

            app(AddCourseHoldToCart::class)->handle($user, $hold, $quantitiesByCourseId);

            $this->dispatch('refresh-sidebar');

            Notification::make()
                ->title('Held seats added to cart')
                ->body('Your held price and expiration will be applied at checkout.')
                ->success()
                ->send();

            $this->redirect(Cart::getUrl());
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->title('Could not add held seats')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }
}
