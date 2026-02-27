<?php

declare(strict_types=1);

namespace App\Filament\User\Pages;

use App\Actions\Store\SwitchPaymentPlanMethod;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentPlanMethod;
use App\Models\PaymentPlan;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use InvalidArgumentException;
use Livewire\Attributes\Url;

final class MyPaymentPlans extends Page implements HasTable
{
    use InteractsWithTable {
        makeTable as makeBaseTable;
    }

    #[Url(as: 'reordering')]
    public bool $isTableReordering = false;

    /**
     * @var array<string, mixed> | null
     */
    #[Url(as: 'filters')]
    public ?array $tableFilters = null;

    #[Url(as: 'grouping')]
    public ?string $tableGrouping = null;

    /**
     * @var ?string
     */
    #[Url(as: 'search')]
    public $tableSearch = '';

    #[Url(as: 'sort')]
    public ?string $tableSort = null;

    protected static ?string $title = 'My Payment Plans';

    protected static ?string $slug = 'payment-plans';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 4;

    public static function getNavigationBadge(): ?string
    {
        $count = PaymentPlan::query()
            ->whereHas('order', fn ($q) => $q->where('user_id', auth()->id()))
            ->whereHas('installments', fn ($q) => $q->where('status', InstallmentStatus::Pending))
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                EmbeddedTable::make(),
            ]);
    }

    protected function makeTable(): Table
    {
        return $this->makeBaseTable()
            ->query(
                PaymentPlan::query()
                    ->whereHas('order', fn ($q) => $q->where('user_id', auth()->id()))
                    ->with(['order', 'template', 'installments'])
            )
            ->columns([
                TextColumn::make('order.id')
                    ->label('Order')
                    ->formatStateUsing(fn (int $state): string => "#{$state}")
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2))
                    ->sortable(),
                TextColumn::make('number_of_installments')
                    ->label('Installments')
                    ->sortable(),
                TextColumn::make('frequency')
                    ->label('Frequency')
                    ->badge(),
                TextColumn::make('method')
                    ->label('Method')
                    ->badge(),
                TextColumn::make('amount_paid')
                    ->label('Paid')
                    ->state(fn (PaymentPlan $record): string => '$'.number_format($record->amountPaid() / 100, 2)),
                TextColumn::make('remaining_balance')
                    ->label('Remaining')
                    ->state(fn (PaymentPlan $record): string => '$'.number_format($record->remainingBalance() / 100, 2)),
                TextColumn::make('next_due')
                    ->label('Next Due')
                    ->state(function (PaymentPlan $record): string {
                        /** @var \App\Models\Installment|null $nextInstallment */
                        $nextInstallment = $record->installments
                            ->where('status', InstallmentStatus::Pending)
                            ->sortBy('due_date')
                            ->first();

                        if ($nextInstallment === null) {
                            return $record->isFullyPaid() ? 'Fully Paid' : 'N/A';
                        }

                        return $nextInstallment->due_date->format('M j, Y');
                    }),
            ])
            ->recordActions([
                Action::make('switchMethod')
                    ->label('Switch Method')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (PaymentPlan $record): bool => ! $record->isFullyPaid())
                    ->form([
                        Select::make('method')
                            ->label('Payment Method')
                            ->options(PaymentPlanMethod::class)
                            ->required(),
                    ])
                    ->action(function (PaymentPlan $record, array $data): void {
                        try {
                            $switchMethod = new SwitchPaymentPlanMethod;
                            $switchMethod->handle($record, PaymentPlanMethod::from($data['method']));

                            Notification::make()
                                ->title('Payment method updated')
                                ->success()
                                ->send();
                        } catch (InvalidArgumentException $e) {
                            Notification::make()
                                ->title('Could not switch method')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('viewInstallments')
                    ->label('View Installments')
                    ->icon(Heroicon::OutlinedEye)
                    ->modalHeading(fn (PaymentPlan $record): string => "Installments for Order #{$record->order_id}")
                    ->modalContent(function (PaymentPlan $record): \Illuminate\Contracts\View\View {
                        $record->loadMissing('installments');

                        return view('filament.user.pages.installments-modal', [
                            'installments' => $record->installments->sortBy('installment_number'),
                        ]);
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ])
            ->emptyStateHeading('No payment plans')
            ->emptyStateDescription('You don\'t have any active payment plans.');
    }
}
