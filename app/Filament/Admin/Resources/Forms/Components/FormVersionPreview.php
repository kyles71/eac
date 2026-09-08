<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Forms\Components;

use App\Models\FormVersion;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Kyle\FilamentFormBuilder\Filament\Resources\Forms\Schemas\FormVersionPreviewSchema;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Reactive;
use Livewire\Component;

final class FormVersionPreview extends Component implements HasSchemas
{
    use InteractsWithSchemas;
    use RestrictsFileUploadsToSchemaComponents;

    #[Locked]
    public FormVersion $version;

    /** @var array<string, mixed> */
    #[Reactive]
    public array $authoringState = [];

    /** @var array<string, mixed> */
    public array $previewData = [];

    public function preview(Schema $schema): Schema
    {
        return $schema
            ->statePath('previewData')
            ->components(fn (): array => app(FormVersionPreviewSchema::class)->components(
                $this->version,
                $this->authoringState,
            ));
    }

    public function render(): View
    {
        return view('filament.admin.resources.forms.components.form-version-preview');
    }
}
