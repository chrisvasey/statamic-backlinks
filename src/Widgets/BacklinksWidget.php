<?php

namespace Chrisvasey\StatamicBacklinks\Widgets;

use Chrisvasey\StatamicBacklinks\BacklinkIndexer;
use Illuminate\Support\Facades\Gate;
use Statamic\Entries\Entry;
use Statamic\Widgets\Widget;

class BacklinksWidget extends Widget
{
    protected static $handle = 'backlinks';

    public function html(): string
    {
        $indexer = app(BacklinkIndexer::class);
        $index = $indexer->getIndex();

        $entriesWithBacklinks = collect($index['links'] ?? [])
            ->flatten()->unique()
            ->map(fn (string $title): ?Entry => $indexer->resolveTitle($title))
            ->filter(fn (?Entry $entry): bool => $entry && Gate::allows('view', $entry))
            ->unique(fn (Entry $entry): string => $entry->id())
            ->map(function (Entry $entry) use ($indexer, $index): array {
                $backlinks = $indexer->getBacklinksFor($entry)->filter(fn (Entry $source): bool => Gate::allows('view', $source));
                $outgoing = collect($index['links'][$entry->id()] ?? [])
                    ->map(fn (string $title): ?Entry => $indexer->resolveTitle($title))
                    ->filter(fn (?Entry $target): bool => $target && Gate::allows('view', $target));

                return ['entry' => $entry, 'backlink_count' => $backlinks->count(), 'links_to' => $outgoing->count()];
            })
            ->filter(fn (array $row): bool => $row['backlink_count'] > 0)
            ->take($this->config('limit', 10))->values();

        return view('statamic-backlinks::widgets.backlinks', [
            'title' => $this->config('title', 'Backlinks'),
            'entries' => $entriesWithBacklinks,
        ])->render();
    }
}
