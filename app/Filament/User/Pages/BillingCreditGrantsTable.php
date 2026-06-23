<?php

declare(strict_types=1);

namespace App\Filament\User\Pages;

use App\Models\CreditGrant;
use App\Models\GiftCard;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

final class BillingCreditGrantsTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;
    use RestrictsFileUploadsToSchemaComponents;

    public const string TYPE_STORE = 'store';

    public const string TYPE_LIMITED_USE = 'limited-use';

    public string $type = self::TYPE_STORE;

    public function mount(string $type = self::TYPE_STORE): void
    {
        $this->type = in_array($type, [self::TYPE_STORE, self::TYPE_LIMITED_USE], true)
            ? $type
            : self::TYPE_STORE;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->creditGrantsQuery())
            ->heading($this->type === self::TYPE_LIMITED_USE ? 'Limited Use Credit' : 'Store Credit')
            ->columns($this->columns())
            ->queryStringIdentifier("billing-{$this->type}-credits")
            ->deferLoading(false)
            ->reorderableColumns(false)
            ->paginated(false)
            ->emptyStateHeading($this->type === self::TYPE_LIMITED_USE ? 'No limited use credit' : 'No store credit')
            ->emptyStateDescription($this->type === self::TYPE_LIMITED_USE
                ? 'Limited use credit balances will appear here.'
                : 'Available store credit balances will appear here.')
            ->emptyStateIcon(Heroicon::OutlinedCreditCard);
    }

    public function render(): View
    {
        return view('filament.user.pages.billing-credit-grants-table');
    }

    /**
     * @return Builder<CreditGrant>
     */
    private function creditGrantsQuery(): Builder
    {
        $query = CreditGrant::query()
            ->where('user_id', auth()->id())
            ->available()
            ->with(['grantedBy', 'products', 'source'])
            ->orderByRaw('expires_on IS NULL')
            ->orderBy('expires_on')
            ->orderBy('created_at');

        if ($this->type === self::TYPE_LIMITED_USE) {
            return $query->restricted();
        }

        return $query->unrestricted();
    }

    /**
     * @return array<int, TextColumn>
     */
    private function columns(): array
    {
        if ($this->type === self::TYPE_LIMITED_USE) {
            return [
                $this->remainingAmountColumn(),
                TextColumn::make('description')
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('restriction')
                    ->label('Restriction')
                    ->state(fn (CreditGrant $record): string => $this->restrictionLabel($record))
                    ->wrap(),
                $this->expirationColumn(),
            ];
        }

        return [
            $this->remainingAmountColumn(),
            TextColumn::make('source_label')
                ->label('Source')
                ->state(fn (CreditGrant $record): string => $this->sourceLabel($record))
                ->wrap(),
            $this->expirationColumn(),
        ];
    }

    private function remainingAmountColumn(): TextColumn
    {
        return TextColumn::make('remaining_amount')
            ->label('Remaining')
            ->formatStateUsing(fn (int $state): string => format_money($state))
            ->sortable();
    }

    private function expirationColumn(): TextColumn
    {
        return TextColumn::make('expires_on')
            ->label('Expiration')
            ->state(fn (CreditGrant $record): string => $record->expires_on?->format('M j, Y') ?? 'No expiration')
            ->sortable();
    }

    private function sourceLabel(CreditGrant $grant): string
    {
        if ($grant->source instanceof GiftCard) {
            return "Redeemed gift card {$grant->source->code}";
        }

        if ($grant->grantedBy !== null) {
            return "Admin issued: {$grant->description}";
        }

        return $grant->description;
    }

    private function restrictionLabel(CreditGrant $grant): string
    {
        $restrictions = [];

        if ($grant->restricted_to_product_type !== null) {
            $restrictions[] = $grant->restricted_to_product_type->getLabel().' products only';
        }

        if ($grant->has_product_restrictions) {
            $productNames = $grant->products
                ->pluck('name')
                ->filter()
                ->values();

            $restrictions[] = $productNames->isEmpty()
                ? 'Specific products only'
                : ($productNames->count() === 1
                    ? 'Product: '.$productNames->first()
                    : 'Products: '.$productNames->join(', '));
        }

        return implode(' · ', $restrictions);
    }
}
