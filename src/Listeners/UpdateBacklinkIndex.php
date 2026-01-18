<?php

namespace Chrisvasey\StatamicBacklinks\Listeners;

use Chrisvasey\StatamicBacklinks\BacklinkIndexer;
use Statamic\Events\EntryDeleted;
use Statamic\Events\EntrySaved;

class UpdateBacklinkIndex
{
    public function __construct(protected BacklinkIndexer $indexer)
    {
        //
    }

    public function handleEntrySaved(EntrySaved $event): void
    {
        $this->indexer->updateEntry($event->entry);
    }

    public function handleEntryDeleted(EntryDeleted $event): void
    {
        $this->indexer->removeEntry($event->entry->id());
    }
}
