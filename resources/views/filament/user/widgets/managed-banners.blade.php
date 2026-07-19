@php
    use App\Enums\ManagedBannerRenderLocation;

    $banners = $this->banners();
    $isAboveTopbar = $this->renderLocation === ManagedBannerRenderLocation::TopbarBefore->value;
@endphp

<div
    @if ($isAboveTopbar)
        x-data="{
            resizeObserver: null,
            updateManagedBannerHeight() {
                document.documentElement.style.setProperty('--eac-managed-banner-height', this.$el.offsetHeight + 'px')
            },
            init() {
                this.resizeObserver = new ResizeObserver(() => this.updateManagedBannerHeight())
                this.resizeObserver.observe(this.$el)
                this.updateManagedBannerHeight()
            },
            destroy() {
                this.resizeObserver?.disconnect()
                document.documentElement.style.removeProperty('--eac-managed-banner-height')
            },
        }"
    @endif
    @class([
        'hidden' => $banners->isEmpty(),
        'pb-2' => $banners->isNotEmpty() && $isAboveTopbar,
        'mt-2' => $banners->isNotEmpty() && ! $isAboveTopbar,
    ])
    @if ($banners->isEmpty())
        aria-hidden="true"
    @endif
    data-managed-banners-empty="{{ $banners->isEmpty() ? 'true' : 'false' }}"
    data-managed-banners-location="{{ $this->renderLocation }}"
>
    @if ($banners->isNotEmpty())
        <div class="space-y-2">
        @foreach ($banners as $banner)
            <div wire:key="managed-banner-{{ $banner->id }}">
                <x-filament::callout
                    :color="$banner->tone->getColor()"
                    :description="$banner->message"
                    :heading="$banner->title"
                    :icon="$banner->resolvedIcon()"
                >
                    @if ($banner->hasCallToAction())
                        <x-slot name="footer">
                            <x-filament::button
                                :href="$banner->resolvedCtaUrl()"
                                :target="$banner->cta_new_tab ? '_blank' : null"
                                tag="a"
                                size="sm"
                            >
                                {{ $banner->cta_label }}
                            </x-filament::button>
                        </x-slot>
                    @endif

                    @if ($banner->is_dismissible)
                        <x-slot name="controls">
                            <x-filament::icon-button
                                color="gray"
                                icon="heroicon-m-x-mark"
                                label="Dismiss"
                                size="sm"
                                wire:click="dismiss({{ $banner->id }})"
                            />
                        </x-slot>
                    @endif
                </x-filament::callout>
            </div>
        @endforeach
        </div>
    @endif
</div>
