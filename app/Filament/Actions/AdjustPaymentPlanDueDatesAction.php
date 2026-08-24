<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Actions\Store\AdjustPaymentPlanDueDates;
use App\Enums\InstallmentStatus;
use App\Models\Installment;
use App\Models\PaymentPlan;
use App\Models\User;
use App\Services\PaymentPlanScheduleEmailAvailabilityService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Livewire\Component;
use Throwable;

final class AdjustPaymentPlanDueDatesAction extends Action
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Adjust Due Dates')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->authorize('adjustDueDates')
            ->visible(fn (?PaymentPlan $record): bool => $record instanceof PaymentPlan
                && $record->installments()
                    ->where('status', '!=', InstallmentStatus::Paid->value)
                    ->exists())
            ->modalHeading('Adjust Payment Plan Due Dates')
            ->modalDescription('Automatic payments are attempted at 10:00 AM Eastern on each due date. Paid installments cannot be changed.')
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitActionLabel('Save Due Dates')
            ->fillForm(function (PaymentPlan $record): array {
                $availability = app(PaymentPlanScheduleEmailAvailabilityService::class)
                    ->for($record, 'Payment schedule adjustment requested by an administrator.');

                return [
                    'installments' => $record->installments()
                        ->where('status', '!=', InstallmentStatus::Paid->value)
                        ->orderBy('installment_number')
                        ->get()
                        ->map(fn (Installment $installment): array => [
                            'installment_id' => $installment->id,
                            'summary' => "Installment #{$installment->installment_number} — ".format_money($installment->amount)." — {$installment->status->value}",
                            'current_due_date' => $installment->due_date->toDateString(),
                            'due_date' => $installment->due_date->toDateString(),
                        ])
                        ->all(),
                    'notification_unavailable_reason' => $availability['reason'],
                    'confirm_without_email' => false,
                ];
            })
            ->schema([
                Repeater::make('installments')
                    ->label('Unpaid Installments')
                    ->schema([
                        Hidden::make('installment_id'),
                        Grid::make(2)
                            ->schema([
                                Group::make([
                                    TextEntry::make('summary')
                                        ->hiddenLabel(),
                                    TextEntry::make('current_due_date')
                                        ->label('Current Due Date')
                                        ->date('l, F j, Y'),
                                ]),
                                DatePicker::make('due_date')
                                    ->label('New Due Date')
                                    ->native(false)
                                    ->required()
                                    ->minDate(fn (): string => self::earliestDueDate())
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (DatePicker $component, Component $livewire, ?string $state): void {
                                        $statePath = $component->getStatePath();

                                        if (! is_string($statePath) || $statePath === '') {
                                            return;
                                        }

                                        $livewire->resetValidation($statePath);

                                        if (blank($state) || self::isValidDueDate($state)) {
                                            return;
                                        }

                                        $livewire->addError($statePath, 'The new due date must be tomorrow or later.');
                                    })
                                    ->validationMessages([
                                        'after_or_equal' => 'The new due date must be tomorrow or later.',
                                    ]),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false),
                Hidden::make('notification_unavailable_reason'),
                Group::make([
                    Callout::make('Customer email unavailable')
                        ->description(fn (Get $get): ?string => $get('notification_unavailable_reason'))
                        ->warning(),
                    Checkbox::make('confirm_without_email')
                        ->label('Save without emailing the customer')
                        ->helperText('I understand the customer will not receive the revised schedule by email.')
                        ->accepted(fn (Get $get): bool => filled($get('notification_unavailable_reason'))),
                ])
                    ->visible(fn (Get $get): bool => filled($get('notification_unavailable_reason'))),
                Textarea::make('reason')
                    ->label('Reason — the customer will see this message')
                    ->helperText('Explain the schedule change in customer-friendly language.')
                    ->rows(4)
                    ->required()
                    ->maxLength(2000)
                    ->columnSpan(fn (Get $get): int|string => filled($get('notification_unavailable_reason')) ? 1 : 'full'),
            ])
            ->action(function (Action $action, PaymentPlan $record, array $data): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                $dueDates = collect($data['installments'] ?? [])
                    ->mapWithKeys(fn (array $installment): array => [
                        (int) $installment['installment_id'] => (string) $installment['due_date'],
                    ])
                    ->all();

                try {
                    $result = app(AdjustPaymentPlanDueDates::class)->handle(
                        paymentPlan: $record,
                        adjustedBy: $actor,
                        dueDates: $dueDates,
                        reason: (string) ($data['reason'] ?? ''),
                        confirmWithoutEmail: (bool) ($data['confirm_without_email'] ?? false),
                    );

                    $record->refresh()->load([
                        'installments',
                        'dueDateAdjustments.installment',
                        'dueDateAdjustments.adjustedBy',
                    ]);

                    $notification = Notification::make()
                        ->title($result['adjusted'] === 1
                            ? '1 installment due date updated'
                            : "{$result['adjusted']} installment due dates updated")
                        ->success();

                    if ($result['customer_notification_status'] === 'Skipped') {
                        $notification->body('Customer email was skipped as confirmed.');
                    } elseif ($result['customer_notification_status'] === 'Failed') {
                        $notification
                            ->body((string) $result['customer_notification_note'])
                            ->warning();
                    } else {
                        $notification->body('The revised schedule email was queued for the customer.');
                    }

                    $notification->send();
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Could not adjust due dates')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    $action->halt();
                }
            });
    }

    public static function getDefaultName(): string
    {
        return 'adjustPaymentPlanDueDates';
    }

    private static function earliestDueDate(): string
    {
        return now()
            ->setTimezone((string) config('app.display_timezone', config('app.timezone')))
            ->startOfDay()
            ->addDay()
            ->toDateString();
    }

    private static function isValidDueDate(string $state): bool
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $state);
        } catch (Throwable) {
            return false;
        }

        if (! $date instanceof CarbonImmutable) {
            return false;
        }

        return $date->format('Y-m-d') === $state
            && $date->toDateString() >= self::earliestDueDate();
    }
}
