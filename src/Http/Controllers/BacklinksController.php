<?php

namespace Chrisvasey\StatamicBacklinks\Http\Controllers;

use Chrisvasey\StatamicBacklinks\BacklinkIndexer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Support\Str;

class BacklinksController extends Controller
{
    public function __construct(protected BacklinkIndexer $indexer)
    {
        //
    }

    /**
     * Get backlinks for a specific entry.
     */
    public function show(EntryContract $entry): JsonResponse
    {
        Gate::authorize('view', $entry);

        $backlinks = $this->indexer->getBacklinksFor($entry)
            ->filter(fn (EntryContract $backlink): bool => Gate::allows('view', $backlink));

        return response()->json([
            'count' => $backlinks->count(),
            'backlinks' => $backlinks->map(function ($entry) {
                return [
                    'id' => $entry->id(),
                    'title' => $entry->get('title') ?? $entry->slug(),
                    'url' => $entry->url(),
                    'edit_url' => $entry->editUrl(),
                    'collection' => $entry->collection()->handle(),
                ];
            })->values()->all(),
        ]);
    }

    /**
     * Create a new entry from a wiki-link title.
     */
    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'source_entry_id' => 'nullable|string',
        ]);

        $title = $validated['title'];
        $sourceEntryId = $validated['source_entry_id'] ?? null;

        // Determine which collection to use
        $collectionHandle = $this->determineCollection($sourceEntryId);
        $collection = Collection::find($collectionHandle);

        if (! $collection) {
            return response()->json(['error' => 'Collection not found'], 404);
        }

        // Create the entry
        $slug = Str::slug($title);
        $blueprint = config('backlinks.creation.default_blueprint') ?? $collection->entryBlueprint()->handle();

        $entry = Entry::make()
            ->collection($collection)
            ->blueprint($blueprint)
            ->slug($slug)
            ->data([
                'title' => $title,
            ]);

        Gate::authorize('store', [EntryContract::class, $collection, $entry->site()]);

        if (! config('backlinks.creation.default_published', false) || Gate::denies('publish', $entry)) {
            $entry->published(false);
        }

        $entry->save();

        // Update the index with the new entry
        $this->indexer->updateEntry($entry);

        return response()->json([
            'success' => true,
            'entry' => [
                'id' => $entry->id(),
                'title' => $title,
                'url' => $entry->url(),
                'edit_url' => $entry->editUrl(),
            ],
        ]);
    }

    /**
     * Search for entries by title (for wiki-link autocomplete).
     */
    public function search(Request $request): JsonResponse
    {
        $query = $request->get('q', '');
        $limit = min((int) $request->get('limit', 10), 50);

        $collections = config('backlinks.scan_collections', ['*']);

        $entriesQuery = $collections === ['*']
            ? Entry::query()
            : Entry::query()->whereIn('collection', $collections);

        if ($query !== '') {
            $entriesQuery->where('title', 'like', "%{$query}%");
        }

        $entries = $entriesQuery
            ->orderBy('title')
            ->get()
            ->filter(fn (EntryContract $entry): bool => Gate::allows('view', $entry))
            ->take($limit)
            ->values()
            ->map(function ($entry) {
                return [
                    'id' => $entry->id(),
                    'title' => $entry->get('title') ?? $entry->slug(),
                    'collection' => $entry->collection()->handle(),
                    'collection_title' => $entry->collection()->title(),
                    'url' => $entry->url(),
                    'edit_url' => $entry->editUrl(),
                ];
            });

        return response()->json($entries);
    }

    /**
     * Determine which collection to create the new entry in.
     */
    protected function determineCollection(?string $sourceEntryId): string
    {
        $strategy = config('backlinks.creation.collection_strategy', 'same');

        if ($strategy === 'same' && $sourceEntryId) {
            $sourceEntry = Entry::find($sourceEntryId);
            if ($sourceEntry) {
                Gate::authorize('view', $sourceEntry);

                return $sourceEntry->collection()->handle();
            }
        }

        return config('backlinks.creation.default_collection', 'pages');
    }
}
