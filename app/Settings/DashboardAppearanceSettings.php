<?php

declare(strict_types=1);

namespace App\Settings;

use App\Support\MediaDisks;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelSettings\Settings;

final class DashboardAppearanceSettings extends Settings
{
    public ?string $messages_bullet_image;

    public ?string $quick_links_bullet_image;

    public static function group(): string
    {
        return 'dashboard_appearance';
    }

    public function messagesBulletImageUrl(): ?string
    {
        return $this->bulletImageUrl($this->messages_bullet_image);
    }

    public function quickLinksBulletImageUrl(): ?string
    {
        return $this->bulletImageUrl($this->quick_links_bullet_image);
    }

    private function bulletImageUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        return Storage::disk(MediaDisks::public())->url($path);
    }
}
