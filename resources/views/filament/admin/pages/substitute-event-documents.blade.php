<div>
    <p class="mb-2 text-sm font-medium text-gray-950 dark:text-white">Documents</p>

    @forelse ($documents as $document)
        <p>
            <a class="fi-link fi-color-primary" href="{{ $document['url'] }}" target="_blank" rel="noopener noreferrer">
                {{ $document['name'] }}
            </a>
        </p>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">No documents are available.</p>
    @endforelse
</div>
