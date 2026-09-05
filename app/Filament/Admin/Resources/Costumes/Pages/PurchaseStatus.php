<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Costumes\Pages;

use App\Enums\CostumeOrderStatus;
use App\Filament\Admin\Resources\Costumes\CostumeResource;
use App\Models\Costume;
use App\Models\User;
use App\Services\CostumePurchaseReportService;
use App\Services\CostumePurchaseRequirementService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use LogicException;

final class PurchaseStatus extends ViewRecord implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = CostumeResource::class;

    protected static ?string $title = 'Costume Order Status';

    /** @var Collection<int, array<string, mixed>>|null */
    private ?Collection $requirementRows = null;

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(User::query()->whereKey($this->rows()->pluck('user.id')))
            ->description('Requirements reflect current course enrollments and costume student assignments. Only completed orders count as purchased.')
            ->columns([
                TextColumn::make('full_name')
                    ->label('Household')
                    ->state(fn (User $record): string => $record->fullName)
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(['first_name', 'last_name']),
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('targets')
                    ->label('Students / Enrollment Seats')
                    ->state(fn (User $record): string => implode(', ', $this->row($record)['targets'])),
                TextColumn::make('required')
                    ->state(fn (User $record): int => $this->row($record)['required'])
                    ->numeric(),
                TextColumn::make('purchased')
                    ->state(fn (User $record): int => $this->row($record)['purchased'])
                    ->numeric(),
                TextColumn::make('remaining')
                    ->state(fn (User $record): int => $this->row($record)['remaining'])
                    ->numeric(),
                TextColumn::make('status')
                    ->state(fn (User $record): CostumeOrderStatus => $this->row($record)['status'])
                    ->badge(),
                TextColumn::make('order_numbers')
                    ->label('Orders')
                    ->state(fn (User $record): string => implode(', ', $this->row($record)['order_numbers']))
                    ->placeholder('None'),
                TextColumn::make('most_recent_purchase')
                    ->label('Last Purchase')
                    ->state(fn (User $record): ?string => $this->row($record)['most_recent_purchase'])
                    ->dateTime(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(CostumeOrderStatus::class)
                    ->query(function (Builder $query, array $data): Builder {
                        $status = CostumeOrderStatus::tryFrom((string) ($data['value'] ?? ''));

                        if ($status === null) {
                            return $query;
                        }

                        return $query->whereKey($this->rows()
                            ->where('status', $status)
                            ->pluck('user.id'));
                    }),
            ])
            ->defaultSort('last_name')
            ->paginated(false);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadRequirementReport')
                ->label('Download Order Status')
                ->icon(Heroicon::OutlinedClipboardDocumentList)
                ->action(fn () => app(CostumePurchaseReportService::class)->downloadRequirements($this->costume())),
            Action::make('downloadPurchaseReport')
                ->label('Download Purchases')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn () => app(CostumePurchaseReportService::class)->downloadPurchasesForCostume($this->costume())),
            Action::make('backToCostume')
                ->label('Back to Costume')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(fn (): string => CostumeResource::getUrl('view', ['record' => $this->costume()])),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rows(): Collection
    {
        return $this->requirementRows ??= app(CostumePurchaseRequirementService::class)
            ->rowsForCostume($this->costume());
    }

    /** @return array<string, mixed> */
    private function row(User $user): array
    {
        return $this->rows()->firstOrFail(fn (array $row): bool => $row['user']->is($user));
    }

    private function costume(): Costume
    {
        $record = $this->getRecord();

        if (! $record instanceof Costume) {
            throw new LogicException('Costume order status pages require a costume record.');
        }

        return $record;
    }
}
