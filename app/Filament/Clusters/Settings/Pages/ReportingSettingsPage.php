<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\Course;
use App\Models\Role;
use App\Models\User;
use App\Settings\ReportingSettings;
use App\Support\Filament\AdminNavigation;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Spatie\Tags\Tag;

final class ReportingSettingsPage extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string $settings = ReportingSettings::class;

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?int $navigationSort = AdminNavigation::SettingsReporting;

    protected static ?string $navigationLabel = 'Reporting';

    protected static ?string $title = 'Reporting Settings';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->hasAnyRole([Role::OWNER, Role::SUPER_ADMIN]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Enrollment Dashboard')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('not_running_maximum_enrollments')
                        ->label('Not Running Maximum Enrollments')
                        ->numeric()
                        ->minValue(0)
                        ->required(),
                    TextInput::make('near_sold_out_maximum_remaining')
                        ->label('Near Sold Out Maximum Remaining')
                        ->numeric()
                        ->minValue(1)
                        ->required(),
                    Repeater::make('capacity_metrics')
                        ->label('Capacity Metrics')
                        ->helperText('Create one chart per metric. Each selected course tag is displayed as its own capacity percentage bar.')
                        ->schema([
                            TextInput::make('name')
                                ->label('Metric Name')
                                ->placeholder('Level')
                                ->required()
                                ->distinct()
                                ->maxLength(100),
                            Select::make('tag_slugs')
                                ->label('Course Tags')
                                ->options(fn (): array => self::courseTagOptions())
                                ->multiple()
                                ->searchable()
                                ->preload()
                                ->required()
                                ->minItems(1),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->addActionLabel('Add capacity metric')
                        ->columnSpanFull(),
                    Select::make('excluded_course_tag_slugs')
                        ->label('Dashboard Excluded Course Tags')
                        ->helperText('Courses with any selected tag are omitted from the enrollment dashboard totals and alerts.')
                        ->options(fn (): array => self::courseTagOptions())
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->sanitizeCourseTagSettings($data);
    }

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->sanitizeCourseTagSettings($data);
    }

    /** @return array<string, string> */
    private static function courseTagOptions(): array
    {
        return Tag::query()
            ->withType(Course::GENERAL_TAG_TYPE)
            ->orderBy('order_column')
            ->get()
            ->mapWithKeys(fn (Tag $tag): array => [(string) $tag->slug => (string) $tag->name])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitizeCourseTagSettings(array $data): array
    {
        $validSlugs = array_fill_keys(array_keys(self::courseTagOptions()), true);
        $capacityMetrics = $data['capacity_metrics'] ?? [];
        $sanitizedMetrics = [];

        foreach (is_array($capacityMetrics) ? $capacityMetrics : [] as $capacityMetric) {
            if (! is_array($capacityMetric)) {
                continue;
            }

            $name = $capacityMetric['name'] ?? null;
            $tagSlugs = $this->validTagSlugs($capacityMetric['tag_slugs'] ?? [], $validSlugs);

            if (! is_string($name) || blank($name) || $tagSlugs === []) {
                continue;
            }

            $sanitizedMetrics[] = [
                'name' => mb_trim($name),
                'tag_slugs' => $tagSlugs,
            ];
        }

        $data['capacity_metrics'] = $sanitizedMetrics;
        $data['excluded_course_tag_slugs'] = $this->validTagSlugs(
            $data['excluded_course_tag_slugs'] ?? [],
            $validSlugs,
        );

        return $data;
    }

    /**
     * @param  array<string, true>  $validSlugs
     * @return list<string>
     */
    private function validTagSlugs(mixed $tagSlugs, array $validSlugs): array
    {
        $sanitizedSlugs = [];

        foreach (is_array($tagSlugs) ? $tagSlugs : [] as $tagSlug) {
            if (! is_string($tagSlug) || ! array_key_exists($tagSlug, $validSlugs)) {
                continue;
            }

            $sanitizedSlugs[$tagSlug] = true;
        }

        return array_keys($sanitizedSlugs);
    }
}
