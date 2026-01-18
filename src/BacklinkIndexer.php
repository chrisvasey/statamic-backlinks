<?php

namespace Chrisvasey\StatamicBacklinks;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;

class BacklinkIndexer
{
    protected array $index = [];

    protected bool $loaded = false;

    public function __construct()
    {
        //
    }

    /**
     * Extract wiki-links from content.
     *
     * @return Collection<string, string> Returns collection of ['title' => 'display']
     */
    public function extractLinks(string $content): Collection
    {
        $links = collect();

        // Match [[Page Title]] and [[Page Title|Display Text]]
        preg_match_all('/\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $title = trim($match[1]);
            $display = isset($match[2]) ? trim($match[2]) : $title;
            $links->put($title, $display);
        }

        return $links;
    }

    /**
     * Update the index for a single entry.
     */
    public function updateEntry(Entry $entry): void
    {
        $this->loadIndex();

        $entryId = $entry->id();
        $content = $this->getEntryContent($entry);
        $links = $this->extractLinks($content);

        // Update the links index
        if ($links->isNotEmpty()) {
            $this->index['links'][$entryId] = $links->keys()->all();
        } else {
            unset($this->index['links'][$entryId]);
        }

        // Update the titles index
        $title = $entry->get('title') ?? $entry->slug();
        $normalizedTitle = $this->normalizeTitle($title);
        $this->index['titles'][$normalizedTitle] = $entryId;

        $this->saveIndex();
    }

    /**
     * Remove an entry from the index.
     */
    public function removeEntry(string $entryId): void
    {
        $this->loadIndex();

        // Remove from links index
        unset($this->index['links'][$entryId]);

        // Remove from titles index
        $this->index['titles'] = array_filter(
            $this->index['titles'] ?? [],
            fn ($id) => $id !== $entryId
        );

        $this->saveIndex();
    }

    /**
     * Get all entries that link to a given entry.
     */
    public function getBacklinksFor(Entry $entry): Collection
    {
        $this->loadIndex();

        $title = $entry->get('title') ?? $entry->slug();
        $normalizedTitle = $this->normalizeTitle($title);

        $backlinks = collect();

        foreach ($this->index['links'] ?? [] as $sourceEntryId => $linkedTitles) {
            foreach ($linkedTitles as $linkedTitle) {
                if ($this->normalizeTitle($linkedTitle) === $normalizedTitle) {
                    $sourceEntry = EntryFacade::find($sourceEntryId);
                    if ($sourceEntry) {
                        $backlinks->push($sourceEntry);
                    }
                    break;
                }
            }
        }

        return $backlinks;
    }

    /**
     * Rebuild the entire index.
     */
    public function rebuild(): void
    {
        $this->index = ['links' => [], 'titles' => []];

        $collections = config('backlinks.scan_collections', ['*']);

        $entries = $collections === ['*']
            ? EntryFacade::all()
            : EntryFacade::whereInCollection($collections)->get();

        foreach ($entries as $entry) {
            $entryId = $entry->id();
            $content = $this->getEntryContent($entry);
            $links = $this->extractLinks($content);

            if ($links->isNotEmpty()) {
                $this->index['links'][$entryId] = $links->keys()->all();
            }

            $title = $entry->get('title') ?? $entry->slug();
            $normalizedTitle = $this->normalizeTitle($title);
            $this->index['titles'][$normalizedTitle] = $entryId;
        }

        $this->saveIndex();
    }

    /**
     * Resolve a title to an entry.
     */
    public function resolveTitle(string $title): ?Entry
    {
        $this->loadIndex();

        $normalizedTitle = $this->normalizeTitle($title);
        $entryId = $this->index['titles'][$normalizedTitle] ?? null;

        if (! $entryId) {
            return null;
        }

        return EntryFacade::find($entryId);
    }

    /**
     * Check if a title exists in the index.
     */
    public function titleExists(string $title): bool
    {
        $this->loadIndex();

        return isset($this->index['titles'][$this->normalizeTitle($title)]);
    }

    /**
     * Get all content from an entry's configured fields.
     */
    protected function getEntryContent(Entry $entry): string
    {
        $fields = config('backlinks.scan_fields', ['content', 'body', 'text', '*']);
        $content = '';

        if (in_array('*', $fields)) {
            // Scan all fields
            foreach ($entry->data()->all() as $value) {
                $content .= $this->extractTextFromValue($value) . ' ';
            }
        } else {
            // Scan specific fields
            foreach ($fields as $field) {
                $value = $entry->get($field);
                if ($value) {
                    $content .= $this->extractTextFromValue($value) . ' ';
                }
            }
        }

        return $content;
    }

    /**
     * Extract text content from a field value (handles Bard, etc).
     */
    protected function extractTextFromValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            // Handle Bard/Replicator content
            return $this->extractTextFromArray($value);
        }

        return '';
    }

    /**
     * Recursively extract text from array structures (Bard, etc).
     */
    protected function extractTextFromArray(array $array): string
    {
        $text = '';

        foreach ($array as $key => $value) {
            if ($key === 'text' && is_string($value)) {
                $text .= $value . ' ';
            } elseif (is_array($value)) {
                $text .= $this->extractTextFromArray($value) . ' ';
            } elseif (is_string($value)) {
                $text .= $value . ' ';
            }
        }

        return $text;
    }

    /**
     * Normalize a title for comparison.
     */
    protected function normalizeTitle(string $title): string
    {
        return mb_strtolower(trim($title));
    }

    /**
     * Load the index from storage.
     */
    protected function loadIndex(): void
    {
        if ($this->loaded) {
            return;
        }

        $storage = config('backlinks.storage', 'file');

        if ($storage === 'file') {
            $path = config('backlinks.storage_path', storage_path('statamic/backlinks.json'));

            if (File::exists($path)) {
                $this->index = json_decode(File::get($path), true) ?? ['links' => [], 'titles' => []];
            } else {
                $this->index = ['links' => [], 'titles' => []];
            }
        } else {
            $this->index = cache()->get('backlinks.index', ['links' => [], 'titles' => []]);
        }

        $this->loaded = true;
    }

    /**
     * Save the index to storage.
     */
    protected function saveIndex(): void
    {
        $storage = config('backlinks.storage', 'file');

        if ($storage === 'file') {
            $path = config('backlinks.storage_path', storage_path('statamic/backlinks.json'));

            $directory = dirname($path);
            if (! File::isDirectory($directory)) {
                File::makeDirectory($directory, 0755, true);
            }

            File::put($path, json_encode($this->index, JSON_PRETTY_PRINT));
        } else {
            cache()->forever('backlinks.index', $this->index);
        }
    }

    /**
     * Get the raw index data (for debugging).
     */
    public function getIndex(): array
    {
        $this->loadIndex();

        return $this->index;
    }

    /**
     * Clear the index.
     */
    public function clear(): void
    {
        $this->index = ['links' => [], 'titles' => []];
        $this->saveIndex();
    }
}
