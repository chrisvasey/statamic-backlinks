<?php

namespace Chrisvasey\StatamicBacklinks\Widgets;

use Chrisvasey\StatamicBacklinks\BacklinkIndexer;
use Statamic\Facades\Entry;
use Statamic\Widgets\Widget;

class BacklinksWidget extends Widget
{
    protected static $handle = 'backlinks';

    public function html()
    {
        $indexer = app(BacklinkIndexer::class);
        $index = $indexer->getIndex();

        // Get recent entries with backlinks
        $entriesWithBacklinks = collect($index['links'] ?? [])
            ->filter(fn ($links) => count($links) > 0)
            ->take($this->config('limit', 10))
            ->map(function ($links, $entryId) use ($indexer) {
                $entry = Entry::find($entryId);
                if (! $entry) {
                    return null;
                }

                $backlinks = $indexer->getBacklinksFor($entry);

                return [
                    'entry' => $entry,
                    'backlink_count' => $backlinks->count(),
                    'links_to' => count($links),
                ];
            })
            ->filter()
            ->values();

        return view('statamic-backlinks::widgets.backlinks', [
            'title' => $this->config('title', 'Backlinks'),
            'entries' => $entriesWithBacklinks,
        ])->render();
    }
}
