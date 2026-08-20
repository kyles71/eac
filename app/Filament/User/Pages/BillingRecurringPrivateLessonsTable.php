<?php

declare(strict_types=1);

namespace App\Filament\User\Pages;

use App\Actions\Store\AddToCart;
use App\Enums\RecurringPrivateLessonChargeStatus;
use App\Enums\RecurringPrivateLessonStatus;
use App\Models\RecurringPrivateLessonCharge;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Livewire\Component;

final class BillingRecurringPrivateLessonsTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;
    use RestrictsFileUploadsToSchemaComponents;

    public function table(Table $table): Table
    {
        return $table
            ->query($this->lessonsQuery())
            ->heading('Recurring Private Lessons')
            ->columns([
                TextColumn::make('event.start_time')
                    ->label('Lesson Date')
                    ->dateTime('M j, Y g:i A', timezone: (string) config('app.display_timezone', config('app.timezone')))
                    ->sortable(),
                TextColumn::make('dancer')
                    ->label('Dancer')
                    ->state(fn (RecurringPrivateLessonCharge $record): string => $record->recurringPrivateLesson->student->displayName()),
                TextColumn::make('recurringPrivateLesson.course.name')
                    ->label('Lesson / Style')
                    ->wrap(),
                TextColumn::make('teachers')
                    ->label('Teacher')
                    ->state(fn (RecurringPrivateLessonCharge $record): string => $record->recurringPrivateLesson->course->teachers
                        ->map(fn (User $teacher): string => $teacher->displayName())
                        ->join(', '))
                    ->placeholder('Not assigned')
                    ->wrap(),
                TextColumn::make('amount')
                    ->money('USD', divideBy: 100)
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->multiple()
                    ->options(RecurringPrivateLessonChargeStatus::class),
            ])
            ->recordActions([
                Action::make('pay')
                    ->label('Pay Lesson')
                    ->icon(Heroicon::OutlinedShoppingCart)
                    ->visible(fn (RecurringPrivateLessonCharge $record): bool => $record->getAvailableCapacity($this->household()) === 1)
                    ->action(fn (RecurringPrivateLessonCharge $record) => $this->addLessonToCart($record)),
            ])
            ->headerActions([
                Action::make('payAllBilledLessons')
                    ->label('Pay All Billed Lessons')
                    ->icon(Heroicon::OutlinedShoppingCart)
                    ->visible(fn (): bool => $this->payableChargesQuery()->exists())
                    ->action(fn () => $this->addAllBilledLessonsToCart()),
            ])
            ->queryStringIdentifier('billing-recurring-private-lessons')
            ->deferLoading(false)
            ->reorderableColumns(false)
            ->emptyStateHeading('No recurring private lessons')
            ->emptyStateDescription('Recurring private lessons assigned to your household will appear here.')
            ->emptyStateIcon(Heroicon::OutlinedCalendarDays);
    }

    public function render(): View
    {
        return view('filament.user.pages.billing-recurring-private-lessons-table');
    }

    /** @return Builder<RecurringPrivateLessonCharge> */
    private function lessonsQuery(): Builder
    {
        $now = now();

        return RecurringPrivateLessonCharge::query()
            ->select('recurring_private_lesson_charges.*')
            ->join('events', 'events.id', '=', 'recurring_private_lesson_charges.event_id')
            ->whereHas(
                'recurringPrivateLesson',
                fn (Builder $query): Builder => $query->where('user_id', $this->household()->id),
            )
            ->with([
                'event',
                'product',
                'recurringPrivateLesson.student',
                'recurringPrivateLesson.course.teachers',
            ])
            ->orderByRaw('CASE WHEN events.start_time >= ? THEN 0 ELSE 1 END', [$now])
            ->orderByRaw('CASE WHEN events.start_time >= ? THEN events.start_time END ASC', [$now])
            ->orderByRaw('CASE WHEN events.start_time < ? THEN events.start_time END DESC', [$now]);
    }

    /** @return Builder<RecurringPrivateLessonCharge> */
    private function payableChargesQuery(): Builder
    {
        return RecurringPrivateLessonCharge::query()
            ->where('status', RecurringPrivateLessonChargeStatus::Billed)
            ->whereHas(
                'recurringPrivateLesson',
                fn (Builder $query): Builder => $query
                    ->where('user_id', $this->household()->id)
                    ->where('status', RecurringPrivateLessonStatus::Active),
            )
            ->whereHas('event', fn (Builder $query): Builder => $query
                ->whereNull('cancelled_at')
                ->where('start_time', '>', now()->addDay()));
    }

    private function addLessonToCart(RecurringPrivateLessonCharge $charge): void
    {
        try {
            $charge->loadMissing('product');

            if (! $this->household()->cartItems()->where('product_id', $charge->product->id)->exists()) {
                app(AddToCart::class)->handle($this->household(), $charge->product);
            }

            $this->redirectToCart();
        } catch (InvalidArgumentException $exception) {
            $this->paymentError('Could not add this lesson', $exception);
        }
    }

    private function addAllBilledLessonsToCart(): void
    {
        try {
            $user = $this->household();
            $existingProductIds = $user->cartItems()->pluck('product_id');

            foreach ($this->payableChargesQuery()->with('product')->get() as $charge) {
                if (! $existingProductIds->contains($charge->product->id)) {
                    app(AddToCart::class)->handle($user, $charge->product);
                }
            }

            $this->redirectToCart();
        } catch (InvalidArgumentException $exception) {
            $this->paymentError('Could not add the billed lessons', $exception);
        }
    }

    private function redirectToCart(): void
    {
        $this->dispatch('refresh-sidebar');
        $this->redirect(Cart::getUrl());
    }

    private function paymentError(string $title, InvalidArgumentException $exception): void
    {
        Notification::make()
            ->title($title)
            ->body($exception->getMessage())
            ->danger()
            ->send();
    }

    private function household(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
