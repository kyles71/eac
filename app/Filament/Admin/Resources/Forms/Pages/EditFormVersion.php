<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Forms\Pages;

use App\Filament\Admin\Resources\Forms\Components\FormVersionPreview;
use App\Filament\Admin\Resources\Forms\FormResource;
use App\Models\Form;
use App\Models\FormVersion;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class EditFormVersion extends \Kyle\FilamentFormBuilder\Filament\Resources\Forms\Pages\EditFormVersion
{
    protected static string $resource = FormResource::class;

    public function preview(Schema $schema): Schema
    {
        return $schema
            ->statePath('previewData')
            ->components(fn (): array => [
                Section::make('Live preview')
                    ->description($this->previewVersion()->versionLabel())
                    ->columnSpanFull()
                    ->schema([
                        Livewire::make(FormVersionPreview::class, fn (): array => [
                            'version' => $this->previewVersion(),
                            'authoringState' => is_array($this->data) ? $this->data : [],
                        ])
                            ->key('form-version-preview'),
                    ]),
            ]);
    }

    private function previewVersion(): FormVersion
    {
        $record = $this->getRecord();
        abort_unless($record instanceof Form, 404);

        return FormVersion::query()
            ->where('form_id', $record->getKey())
            ->findOrFail($this->versionId);
    }
}
