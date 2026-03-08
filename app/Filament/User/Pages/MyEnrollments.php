<?php

declare(strict_types=1);

namespace App\Filament\User\Pages;

use App\Filament\Admin\Resources\Enrollments\Tables\EnrollmentsTable;
use BackedEnum;
use Carbon\Carbon;
use Filament\Pages\Page;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

final class MyEnrollments extends Page implements HasTable
{
    use HasTabs;
    use InteractsWithTable {
        makeTable as makeBaseTable;
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

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

    #[Url(as: 'tab')]
    public ?string $activeTab = null;

    protected static ?string $title = 'My Classes';

    public function mount(): void
    {
        $this->loadDefaultActiveTab();
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getTabsContentComponent()
                    ->activeTab(1),
                EmbeddedTable::make(),
            ]);
    }

    public function getTabs(): array
    {
        $now = Carbon::now();

        return [
            'open' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->open()),
            'active' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->select('enrollments.*')->active($now)),
            'future' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->future($now)),
            'past' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->select('enrollments.*')->past($now)),
            'all' => Tab::make(),
        ];
    }

    protected function makeTable(): Table
    {
        $table = $this->makeBaseTable();

        return EnrollmentsTable::configure($table, true)
            ->modifyQueryUsing($this->modifyQueryWithActiveTab(...));
    }
}
