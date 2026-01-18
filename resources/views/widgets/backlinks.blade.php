<div class="card p-0">
    <div class="flex justify-between items-center p-4 border-b dark:border-dark-900">
        <h2 class="font-bold">{{ $title }}</h2>
    </div>

    <div class="p-4">
        @if($entries->isEmpty())
            <p class="text-gray-600 dark:text-dark-200 text-sm">No entries with backlinks found.</p>
            <p class="text-gray-500 dark:text-dark-300 text-xs mt-2">
                Use <code class="bg-gray-100 dark:bg-dark-700 px-1 rounded">[[Page Title]]</code> syntax to create wiki-links.
            </p>
        @else
            <div class="space-y-2">
                @foreach($entries as $item)
                    <div class="flex justify-between items-center text-sm">
                        <a href="{{ $item['entry']->editUrl() }}" class="text-blue-600 dark:text-blue-400 hover:underline">
                            {{ $item['entry']->get('title') ?? $item['entry']->slug() }}
                        </a>
                        <div class="flex gap-3 text-gray-500 dark:text-dark-300 text-xs">
                            <span title="Links to other pages">{{ $item['links_to'] }} outgoing</span>
                            <span title="Pages linking here">{{ $item['backlink_count'] }} incoming</span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
