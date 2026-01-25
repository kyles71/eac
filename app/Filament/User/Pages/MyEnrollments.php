<?php

namespace App\Filament\User\Pages;

use App\Filament\Resources\Enrollments\Tables\EnrollmentsTable;
use Carbon\Carbon;
use Filament\Pages\Page;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class MyEnrollments extends Page implements HasTable
{
    use HasTabs;
    use InteractsWithTable {
        makeTable as makeBaseTable;
    }

    protected static ?string $title = 'My Classes';

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

    protected function makeTable(): Table
    {
        $table = $this->makeBaseTable();

        return EnrollmentsTable::configure($table, true)
            ->modifyQueryUsing($this->modifyQueryWithActiveTab(...));
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
}
