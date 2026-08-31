<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets\Reports;

use App\Enums\ReportWidgetKey;
use App\Models\AcademicTerm;
use App\Models\User;
use App\Services\Reports\EnrollmentReportService;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Livewire\Attributes\Locked;

final class CapacityMetricChart extends ChartWidget
{
    use InteractsWithPageFilters;

    #[Locked]
    public string $metricName = '';

    /** @var list<string> */
    #[Locked]
    public array $tagSlugs = [];

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '300px';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ReportWidgetKey::EnrollmentCapacityMetrics->canView($user);
    }

    public function getHeading(): string
    {
        return $this->metricName;
    }

    public function getDescription(): string
    {
        $term = $this->selectedTerm(app(EnrollmentReportService::class));
        $termName = $term instanceof AcademicTerm
            ? $term->display_name
            : 'No current term';

        return "{$termName} capacity used by course tag";
    }

    protected function getData(): array
    {
        $user = auth()->user();

        if (! $user instanceof User || ! ReportWidgetKey::EnrollmentCapacityMetrics->canView($user)) {
            return [];
        }

        $service = app(EnrollmentReportService::class);
        $tagCapacities = $service->capacityByTags(
            $this->selectedTerm($service),
            $user,
            $this->tagSlugs,
        );

        return [
            'datasets' => [[
                'label' => 'Capacity Used (%)',
                'data' => array_column($tagCapacities, 'percentage'),
            ]],
            'labels' => array_column($tagCapacities, 'label'),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => false],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'suggestedMax' => 100,
                    'title' => [
                        'display' => true,
                        'text' => 'Capacity Used (%)',
                    ],
                ],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    private function selectedTerm(EnrollmentReportService $service): ?AcademicTerm
    {
        $id = filter_var($this->pageFilters['academic_term_id'] ?? null, FILTER_VALIDATE_INT);

        return $id === false
            ? $service->currentTerm()
            : AcademicTerm::query()->find($id);
    }
}
