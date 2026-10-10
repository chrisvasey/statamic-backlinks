<?php

namespace Chrisvasey\StatamicBacklinks\Tags;

use Chrisvasey\StatamicBacklinks\BacklinkIndexer;
use Illuminate\Support\Collection;
use Statamic\Facades\Entry;
use Statamic\Tags\Tags;

class Backlinks extends Tags
{
    protected static $handle = 'backlinks';

    protected BacklinkIndexer $indexer;

    public function __construct(BacklinkIndexer $indexer)
    {
        $this->indexer = $indexer;
    }

    /**
     * {{ backlinks }} ... {{ /backlinks }}
     * {{ backlinks from="entry-id" }} ... {{ /backlinks }}
     */
    public function index(): array|string
    {
        $backlinks = $this->getPublicBacklinks();

        if ($backlinks->isEmpty()) {
            return '';
        }

        return $this->parseLoop($backlinks->map(function ($entry) {
            return $entry->toAugmentedArray();
        })->all());
    }

    /**
     * {{ backlinks:count }}
     * {{ backlinks:count from="entry-id" }}
     */
    public function count(): int
    {
        return $this->getPublicBacklinks()->count();
    }

    /**
     * @return Collection<int, \Statamic\Entries\Entry>
     */
    protected function getPublicBacklinks(): Collection
    {
        $entry = $this->getTargetEntry();

        if (! $entry) {
            return collect();
        }

        return $this->indexer->getBacklinksFor($entry)->filter(
            fn (\Statamic\Entries\Entry $entry): bool => $entry->status() === 'published' && $entry->url()
        );
    }

    /**
     * Get the target entry to find backlinks for.
     */
    protected function getTargetEntry(): ?\Statamic\Entries\Entry
    {
        // If 'from' parameter is provided, use that entry ID
        if ($from = $this->params->get('from')) {
            return Entry::find($from);
        }

        // Otherwise, try to get the current entry from context
        $id = $this->context->get('id');

        if ($id) {
            return Entry::find($id);
        }

        return null;
    }
}
