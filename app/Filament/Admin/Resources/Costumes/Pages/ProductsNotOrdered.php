<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Costumes\Pages;

use App\Filament\Admin\Resources\Costumes\CostumeResource;
use App\Services\CostumePurchaseReportService;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final class ProductsNotOrdered extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = CostumeResource::class;

    protected static ?string $title = 'Costume Products Not Ordered';

    protected string $view = 'filament.admin.resources.costumes.pages.products-not-ordered';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?string $search, int $page, int $recordsPerPage): LengthAwarePaginator => $this->records(
                $search,
                $page,
                $recordsPerPage,
            ))
            ->description('Shows households with a Not Ordered status for active, required Costume product listings.')
            ->columns([
                TextColumn::make('costume')
                    ->url(fn (array $record): string => CostumeResource::getUrl('view', ['record' => $record['costume_id']])),
                TextColumn::make('vendor')
                    ->placeholder('None'),
                TextColumn::make('vendor_number')
                    ->label('Vendor Number')
                    ->placeholder('None'),
                TextColumn::make('costume_color')
                    ->label('Costume Color')
                    ->placeholder('None'),
                TextColumn::make('course'),
                TextColumn::make('academic_term')
                    ->label('Academic Term'),
                TextColumn::make('product')
                    ->label('Product Listing'),
                TextColumn::make('household'),
                TextColumn::make('household_email')
                    ->label('Household Email'),
                TextColumn::make('targets')
                    ->label('Qualifying Students / Seats')
                    ->wrap(),
                TextColumn::make('required')
                    ->label('Required Quantity')
                    ->numeric(),
                TextColumn::make('reminder_on')
                    ->label('Reminder Date')
                    ->date()
                    ->placeholder('None'),
                TextColumn::make('deadline')
                    ->label('Purchase Deadline')
                    ->dateTime(),
                TextColumn::make('status')
                    ->badge()
                    ->color('danger'),
            ])
            ->searchable()
            ->emptyStateHeading('No active Costume products are Not Ordered')
            ->emptyStateDescription('Every current requirement has at least one completed purchase, or no active Costume purchase requirements exist.');
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadProductsNotOrdered')
                ->label('Download Products Not Ordered')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn () => app(CostumePurchaseReportService::class)->downloadNotOrdered()),
            Action::make('backToCostumes')
                ->label('Back to Costumes')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(CostumeResource::getUrl()),
        ];
    }

    private function records(?string $search, int $page, int $recordsPerPage): LengthAwarePaginator
    {
        $rows = app(CostumePurchaseReportService::class)
            ->notOrderedRows();

        if (filled($search)) {
            $rows = $rows->filter(
                fn (array $row): bool => Str::contains(
                    Str::lower(implode(' ', $row)),
                    Str::lower((string) $search),
                ),
            );
        }

        return new LengthAwarePaginator(
            items: $rows->forPage($page, $recordsPerPage),
            total: $rows->count(),
            perPage: $recordsPerPage,
            currentPage: $page,
        );
    }
}
