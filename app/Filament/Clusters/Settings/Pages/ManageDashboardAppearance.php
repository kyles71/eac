<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\User;
use App\Settings\DashboardAppearanceSettings;
use App\Support\MediaDisks;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

final class ManageDashboardAppearance extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaintBrush;

    protected static string $settings = DashboardAppearanceSettings::class;

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?string $navigationLabel = 'Dashboard Appearance';

    protected static ?string $title = 'Dashboard Appearance';

    /**
     * @var array<string>
     */
    protected array $filesToDelete = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('Manage:DashboardAppearance');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Widget Bullet Images')
                    ->description('Each image is used as the bullet for every item in its dashboard widget.')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        self::bulletImageUpload('messages_bullet_image', 'Messages From EAC'),
                        self::bulletImageUpload('quick_links_bullet_image', 'Quick Links'),
                    ]),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $settings = app(DashboardAppearanceSettings::class);

        foreach (['messages_bullet_image', 'quick_links_bullet_image'] as $property) {
            $previousPath = $settings->{$property};
            $newPath = $data[$property] ?? null;

            if (filled($previousPath) && $previousPath !== $newPath) {
                $this->filesToDelete[] = $previousPath;
            }
        }

        return $data;
    }

    protected function afterSave(): void
    {
        Storage::disk(MediaDisks::public())->delete($this->filesToDelete);

        $this->filesToDelete = [];
    }

    private static function bulletImageUpload(string $name, string $label): FileUpload
    {
        return FileUpload::make($name)
            ->label($label)
            ->helperText('Upload a square PNG, JPG, WebP, or SVG. Cropping or editing an SVG converts it to PNG.')
            ->image()
            ->acceptedFileTypes([
                'image/jpeg',
                'image/png',
                'image/svg+xml',
                'image/webp',
            ])
            ->disk(MediaDisks::public())
            ->visibility('public')
            ->directory('dashboard/bullets')
            ->maxSize(2048)
            ->imageAspectRatio('1:1')
            ->imageEditor()
            ->imageEditorAspectRatioOptions(['1:1'])
            ->automaticallyOpenImageEditorForAspectRatio()
            ->confirmSvgEditing()
            ->columnSpan(1);
    }
}
