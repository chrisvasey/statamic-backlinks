<?php

namespace Chrisvasey\StatamicBacklinks;

use Closure;
use DOMDocument;
use DOMText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Markdown;
use Statamic\Fieldtypes\Bard;
use Statamic\Fieldtypes\Bard\Augmentor;

class BacklinkIndexer
{
    protected array $index = [];

    protected bool $loaded = false;

    /**
     * Extract wiki-links from content.
     *
     * @return Collection<string, string> Returns collection of ['title' => 'display']
     */
    public function extractLinks(string $content): Collection
    {
        $links = collect();

        app(WikiLinkParser::class)->transform(Markdown::parse($content), function (DOMDocument $document, string $title, string $display) use ($links): DOMText {
            $links->put($title, $display);

            return $document->createTextNode($display);
        });

        return $links;
    }

    /**
     * Update the index for a single entry.
     */
    public function updateEntry(Entry $entry): void
    {
        $this->mutate(function () use ($entry): void {
            $this->indexEntry($entry);
        });
    }

    protected function indexEntry(Entry $entry): void
    {
        $entryId = $entry->id();
        unset($this->index['links'][$entryId]);
        $this->index['titles'] = array_filter($this->index['titles'] ?? [], fn ($id): bool => $id !== $entryId);

        if ($this->collectionAllowed($entry, 'scan_collections')) {
            $links = $this->extractLinks($this->getEntryContent($entry));
            if ($links->isNotEmpty()) {
                $this->index['links'][$entryId] = $links->keys()->all();
            }
        }
        if ($this->collectionAllowed($entry, 'linkable_collections')) {
            $title = $this->normalizeTitle($entry->get('title') ?? $entry->slug());
            $this->index['titles'][$title] = $entryId;
        }
    }

    protected function collectionAllowed(Entry $entry, string $setting): bool
    {
        $collections = config('backlinks.'.$setting, ['*']);

        return in_array('*', $collections) || in_array($entry->collectionHandle(), $collections);
    }

    /**
     * Remove an entry from the index.
     */
    public function removeEntry(string $entryId): void
    {
        $this->mutate(function () use ($entryId): void {
            unset($this->index['links'][$entryId]);
            $this->index['titles'] = array_filter($this->index['titles'] ?? [], fn ($id): bool => $id !== $entryId);
        });
    }

    /**
     * Get all entries that link to a given entry.
     */
    public function getBacklinksFor(Entry $entry): Collection
    {
        $this->loadIndex();

        $backlinks = collect();

        foreach ($this->index['links'] ?? [] as $sourceEntryId => $linkedTitles) {
            foreach ($linkedTitles as $linkedTitle) {
                if ($this->resolveTitle($linkedTitle)?->id() === $entry->id()) {
                    $sourceEntry = EntryFacade::find($sourceEntryId);
                    if ($sourceEntry && $this->collectionAllowed($sourceEntry, 'scan_collections')) {
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
        $this->mutate(function (): void {
            $this->index = ['links' => [], 'titles' => []];
            foreach (EntryFacade::all() as $entry) {
                $this->indexEntry($entry);
            }
        });
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

        $entry = EntryFacade::find($entryId);

        return $entry && $this->collectionAllowed($entry, 'linkable_collections') ? $entry : null;
    }

    /**
     * Check if a title exists in the index.
     */
    public function titleExists(string $title): bool
    {
        $this->loadIndex();

        return $this->resolveTitle($title) !== null;
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
                $content .= $this->extractTextFromValue($value)."\n\n";
            }
        } else {
            // Scan specific fields
            foreach ($fields as $field) {
                $value = $entry->get($field);
                if ($value) {
                    $content .= $this->extractTextFromValue($value)."\n\n";
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
        if (($array['type'] ?? null) === 'set') {
            return $this->extractTextFromArray($array['attrs']['values'] ?? []);
        }
        if (isset($array['type']) && (is_array($array['content'] ?? null) || isset($array['attrs']))) {
            return (new Augmentor(new Bard))->convertToHtml([$array])."\n\n";
        }

        return implode("\n\n", array_map(fn (mixed $value): string => $this->extractTextFromValue($value), $array));
    }

    /**
     * Normalize a title for comparison.
     */
    protected function normalizeTitle(string $title): string
    {
        return mb_strtolower(trim($title));
    }

    protected function mutate(Closure $callback): void
    {
        $write = function () use ($callback): void {
            $this->loaded = false;
            $this->loadIndex();
            $callback();
            $this->saveIndex();
        };
        if (config('backlinks.storage', 'file') !== 'file') {
            cache()->lock('backlinks.index.lock', 60)->block(10, $write);

            return;
        }

        $path = config('backlinks.storage_path', storage_path('statamic/backlinks.json'));
        File::ensureDirectoryExists(dirname($path));
        $lock = fopen($path.'.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Unable to open backlinks index lock.');
        }
        try {
            if (! flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Unable to lock backlinks index.');
            }
            $write();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
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

            File::replace($path, json_encode($this->index, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
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
        $this->mutate(function (): void {
            $this->index = ['links' => [], 'titles' => []];
        });
    }
}
