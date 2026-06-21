<div class="max-w-72 space-y-3 text-left">
    @if ($staffPhotoUrl)
        <img
            src="{{ $staffPhotoUrl }}"
            alt=""
            class="max-h-64 w-full rounded-lg object-cover"
        >
    @endif

    @if ($staffBio)
        <p class="whitespace-pre-line text-sm">{{ $staffBio }}</p>
    @endif
</div>
