@php
    use Filament\Support\Facades\FilamentAsset;
    use Filament\Support\Icons\Heroicon;
    use Illuminate\Support\Facades\Vite;

    $galleryScriptSrc = FilamentAsset::getScriptSrc('product-gallery');

    if (! Vite::isRunningHot()) {
        $galleryScriptSrc = '/'.ltrim((string) parse_url($galleryScriptSrc, PHP_URL_PATH), '/');
    }
@endphp

<eac-product-gallery
    class="grid grid-cols-1 gap-4 sm:grid-cols-2"
    data-js-as-module="true"
    x-data="{}"
    x-load-js="[@js($galleryScriptSrc)]"
>
    @foreach ($images as $image)
        <a
            aria-label="Open {{ $image->name }} in the image viewer"
            class="group relative block cursor-zoom-in overflow-hidden rounded-lg ring-1 ring-gray-950/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 dark:ring-white/10"
            data-product-gallery-item
            href="{{ $image->getUrl() }}"
            rel="noopener"
            target="_blank"
        >
            <img
                alt="{{ $image->name }}"
                class="h-64 w-full object-cover transition duration-200 group-hover:scale-[1.02] group-focus-visible:scale-[1.02] motion-reduce:transition-none"
                decoding="async"
                loading="eager"
                src="{{ $image->getUrl() }}"
            >

            <span
                aria-hidden="true"
                class="absolute bottom-3 right-3 grid size-9 place-items-center rounded-full bg-black/65 text-white opacity-0 shadow-sm transition group-hover:opacity-100 group-focus-visible:opacity-100 motion-reduce:transition-none"
            >
                <x-filament::icon :icon="Heroicon::OutlinedMagnifyingGlassPlus" class="size-5" />
            </span>
        </a>
    @endforeach
</eac-product-gallery>
