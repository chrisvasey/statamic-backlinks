<?php

namespace Chrisvasey\StatamicBacklinks\Console\Commands;

use Chrisvasey\StatamicBacklinks\BacklinkIndexer;
use Illuminate\Console\Command;

class RebuildIndexCommand extends Command
{
    protected $signature = 'backlinks:rebuild';

    protected $description = 'Rebuild the backlinks index by scanning all configured collections';

    public function handle(BacklinkIndexer $indexer): int
    {
        $this->info('Rebuilding backlinks index...');

        $indexer->rebuild();

        $index = $indexer->getIndex();
        $entryCount = count($index['titles'] ?? []);
        $linkCount = collect($index['links'] ?? [])->flatten()->count();

        $this->info("Done! Indexed {$entryCount} entries with {$linkCount} wiki-links.");

        return self::SUCCESS;
    }
}
