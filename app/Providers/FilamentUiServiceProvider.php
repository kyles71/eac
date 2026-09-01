<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Filament\FilamentUiMacros;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;

final class FilamentUiServiceProvider extends ServiceProvider
{
    private const string DATE_DISPLAY_FORMAT = 'M j, Y';

    private const string DATE_TIME_DISPLAY_FORMAT = 'M j, Y g:i A';

    private const string TIME_DISPLAY_FORMAT = 'g:i A';

    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $displayTimezone = config('app.display_timezone', config('app.timezone', 'UTC'));

        // Used by all Filament date-time pickers, table columns, and infolist entries by default.
        FilamentTimezone::set(is_string($displayTimezone) ? $displayTimezone : 'UTC');

        // When a field has multiple words like "due_date", the label changes from "Due date" to "Due Date".
        Field::configureUsing(function (Field $field) {
            $field->label(function (Field $component) {
                return str($component->getName())
                    ->afterLast('.')
                    ->kebab()
                    ->replace(['-', '_'], ' ')
                    ->ucwords();
            });

            $field->validationAttribute(function (Field $component) {
                return $component->getLabel();
            });

            return $field;
        });

        // make selects searchable by default, but only preload explicitly where the option list is small
        Select::configureUsing(function (Select $field) {
            return $field->searchable();
        });

        // add sensible min and max so you don't end up with dates like 01/01/0000 or 01/01/3000
        DatePicker::configureUsing(function (DatePicker $datePicker) {
            return $datePicker
                ->minDate(Carbon::createFromDate(1500, 1, 1))
                ->maxDate(now()->addYears(30));
        });

        // default to not showing seconds in the datetime picker, adjust as needed
        DateTimePicker::configureUsing(function (DateTimePicker $picker) {
            $picker->seconds(false);
        });

        // US based phone input, adjust for different countries
        TextInput::macro('phone', fn (): TextInput => FilamentUiMacros::phone($this));

        TextInput::macro('moneyCents', fn (float|int $minValue = 0): TextInput => FilamentUiMacros::textInputMoneyCents($this, $minValue));

        TextColumn::macro('moneyCents', fn (?string $placeholder = null): TextColumn => FilamentUiMacros::textColumnMoneyCents($this, $placeholder));

        TextEntry::macro('moneyCents', fn (?string $placeholder = null): TextEntry => FilamentUiMacros::textEntryMoneyCents($this, $placeholder));

        Select::macro('searchableRelationship', function (
            string $name,
            array $searchColumns,
            Closure $labelFromRecord,
            ?Closure $modifyQueryUsing = null,
            array $orderBy = [],
            string $titleAttribute = 'id',
        ): Select {
            return FilamentUiMacros::searchableRelationship(
                select: $this,
                name: $name,
                searchColumns: $searchColumns,
                labelFromRecord: $labelFromRecord,
                modifyQueryUsing: $modifyQueryUsing,
                orderBy: $orderBy,
                titleAttribute: $titleAttribute,
            );
        });

        Select::macro('userRelationship', function (
            string $name = 'user',
            ?Closure $modifyQueryUsing = null,
        ): Select {
            return FilamentUiMacros::userRelationship(
                select: $this,
                name: $name,
                modifyQueryUsing: $modifyQueryUsing,
            );
        });

        Select::macro('studentRelationship', function (
            string $name = 'student',
            ?Closure $modifyQueryUsing = null,
        ): Select {
            return FilamentUiMacros::studentRelationship(
                select: $this,
                name: $name,
                modifyQueryUsing: $modifyQueryUsing,
            );
        });

        SpatieMediaLibraryFileUpload::configureUsing(function (SpatieMediaLibraryFileUpload $upload) {
            return $upload
                ->maxSize(config('app.file_uploads.max_size_kilobytes'));
        });

        SpatieMediaLibraryFileUpload::macro('allowVideo', fn (): SpatieMediaLibraryFileUpload => FilamentUiMacros::allowVideo($this));

        // resource forms in this app often need room for media, repeaters, and grouped sections
        EditAction::configureUsing(function (EditAction $action) {
            $action
                ->slideOver();
        });

        // capitalize the model name in a create action label
        CreateAction::configureUsing(function (CreateAction $action) {
            $action
                ->label(fn (): string => __('filament-actions::create.single.label', ['label' => ucwords($action->getModelLabel())]))
                ->slideOver();
        });

        Action::configureUsing(function (Action $action) {
            $action
                ->stickyModalHeader()
                ->stickyModalFooter();
        });

        // various table presets
        Table::configureUsing(function (Table $table) {
            return $table
                ->striped()
                ->deferLoading()
                ->reorderableColumns()
                // ->columnManagerColumns(2)
                ->defaultDateDisplayFormat(self::DATE_DISPLAY_FORMAT)
                ->defaultDateTimeDisplayFormat(self::DATE_TIME_DISPLAY_FORMAT)
                ->defaultTimeDisplayFormat(self::TIME_DISPLAY_FORMAT)
                ->columnManagerApplyAction(self::configureColumnManagerApplyAction(...))
                ->columnManagerTriggerAction(self::configureColumnManagerTrigger(...))
                ->filtersApplyAction(self::configureFiltersApplyAction(...))
                ->filtersTriggerAction(self::configureFiltersTrigger(...))
                ->filtersFormWidth(Width::Small)
                ->recordActionsPosition(fn (): ?RecordActionsPosition => Filament::getCurrentPanel()?->getId() === 'admin'
                    ? RecordActionsPosition::BeforeCells
                    : null)
                ->paginationPageOptions([10, 25, 50]);
        });

        // make notifications last 10 seconds by default
        Notification::configureUsing(function (Notification $notification) {
            return $notification->duration(10000);
        });

        // use your preferred date displays
        Schema::configureUsing(function (Schema $schema) {
            return $schema
                ->defaultDateDisplayFormat(self::DATE_DISPLAY_FORMAT)
                ->defaultDateTimeDisplayFormat(self::DATE_TIME_DISPLAY_FORMAT)
                ->defaultTimeDisplayFormat(self::TIME_DISPLAY_FORMAT);
        });
    }

    private static function configureColumnManagerApplyAction(Action $action): Action
    {
        return $action->alpineClickHandler('applyTableColumnManager().then(() => close())');
    }

    private static function configureColumnManagerTrigger(Action $action): Action
    {
        $applyAction = $action->getExtraModalFooterActions()['applyTableColumnManager'] ?? null;

        if ($applyAction instanceof Action) {
            $applyAction->alpineClickHandler('applyTableColumnManager().then(() => close())');
        }

        return $action
            ->button()
            ->label('Columns');
    }

    private static function configureFiltersApplyAction(Action $action): Action
    {
        return $action->alpineClickHandler('$wire.applyTableFilters().then(() => close())');
    }

    private static function configureFiltersTrigger(Action $action): Action
    {
        $applyAction = $action->getExtraModalFooterActions()['applyFilters'] ?? null;

        if ($applyAction instanceof Action) {
            $applyAction->alpineClickHandler('$wire.applyTableFilters().then(() => close())');
        }

        return $action
            ->button()
            ->label('Filters')
            ->closeModalByClickingAway(true);
    }
}
