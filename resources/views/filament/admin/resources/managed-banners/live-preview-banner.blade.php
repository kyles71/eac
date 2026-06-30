<div
    class="w-full"
    data-managed-banner-preview-slot-active="{{ $activeRenderLocation->value }}"
    data-managed-banner-preview-title="{{ $title }}"
    data-managed-banner-preview-message="{{ $message }}"
>
    <x-filament::callout
        :color="$tone->getColor()"
        :description="$message"
        :heading="$title"
        :icon="$icon"
    >
        @if (filled($ctaLabel))
            <x-slot name="footer">
                <div class="flex flex-wrap items-center gap-3">
                    @if (filled($ctaUrl))
                        <x-filament::button
                            :href="$ctaUrl"
                            :target="$ctaNewTab ? '_blank' : null"
                            tag="a"
                            size="sm"
                            x-on:click.prevent
                        >
                            {{ $ctaLabel }}
                        </x-filament::button>
                    @else
                        <x-filament::button
                            disabled
                            size="sm"
                        >
                            {{ $ctaLabel }}
                        </x-filament::button>
                    @endif
                </div>
            </x-slot>
        @endif
    </x-filament::callout>
</div>
