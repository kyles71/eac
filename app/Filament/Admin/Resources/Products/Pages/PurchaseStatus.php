<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Products\Pages;

use App\Enums\PurchaseRequirementStatus;
use App\Filament\Admin\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductPurchaseReportService;
use App\Services\ProductPurchaseRequirementService;
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

    protected static string $resource = ProductResource::class;

    protected static ?string $title = 'Product Purchase Status';

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
            ->description('Requirements reflect the current purchase audience and student exclusions. Only completed orders count as purchased.')
            ->columns([
                TextColumn::make('full_name')
                    ->label('Household')
                    ->state(fn (User $record): string => $record->fullName)
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(['first_name', 'last_name']),
                TextColumn::make('email')->searchable(),
                TextColumn::make('targets')
                    ->label('Qualifying Students / Seats')
                    ->state(fn (User $record): string => implode(', ', $this->row($record)['targets'])),
                TextColumn::make('required')->state(fn (User $record): int => $this->row($record)['required'])->numeric(),
                TextColumn::make('purchased')->state(fn (User $record): int => $this->row($record)['purchased'])->numeric(),
                TextColumn::make('remaining')->state(fn (User $record): int => $this->row($record)['remaining'])->numeric(),
                TextColumn::make('status')->state(fn (User $record): PurchaseRequirementStatus => $this->row($record)['status'])->badge(),
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
                    ->options(PurchaseRequirementStatus::class)
                    ->query(function (Builder $query, array $data): Builder {
                        $status = PurchaseRequirementStatus::tryFrom((string) ($data['value'] ?? ''));

                        return $status instanceof PurchaseRequirementStatus
                            ? $query->whereKey($this->rows()->where('status', $status)->pluck('user.id'))
                            : $query;
                    }),
            ])
            ->defaultSort('last_name')
            ->paginated(false);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadRequirementReport')
                ->label('Download Purchase Status')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn () => app(ProductPurchaseReportService::class)->download($this->product())),
            Action::make('backToProduct')
                ->label('Back to Product')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(fn (): string => ProductResource::getUrl('view', ['record' => $this->product()])),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rows(): Collection
    {
        return $this->requirementRows ??= app(ProductPurchaseRequirementService::class)
            ->rowsForProduct($this->product());
    }

    /** @return array<string, mixed> */
    private function row(User $user): array
    {
        return $this->rows()->firstOrFail(fn (array $row): bool => $row['user']->is($user));
    }

    private function product(): Product
    {
        $record = $this->getRecord();

        if (! $record instanceof Product || ! $record->is_purchase_required) {
            throw new LogicException('Purchase status pages require a required Product.');
        }

        return $record;
    }
}
