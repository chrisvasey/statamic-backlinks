<?php

namespace Chrisvasey\StatamicBacklinks\Tests\Feature;

use Chrisvasey\StatamicBacklinks\BacklinkIndexer;
use Chrisvasey\StatamicBacklinks\Tests\TestCase;
use Statamic\Facades\Antlers;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

class BacklinksTagTest extends TestCase
{
    protected BacklinkIndexer $indexer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->indexer = app(BacklinkIndexer::class);

        Collection::make('pages')->save();
    }

    public function test_backlinks_tag_returns_linking_entries()
    {
        // Create target entry
        $targetEntry = Entry::make()
            ->collection('pages')
            ->slug('target')
            ->data(['title' => 'Target Page']);
        $targetEntry->save();

        // Create source entry that links to target
        $sourceEntry = Entry::make()
            ->collection('pages')
            ->slug('source')
            ->data([
                'title' => 'Source Page',
                'content' => 'Check out [[Target Page]] for more.',
            ]);
        $sourceEntry->save();

        // Update the index
        $this->indexer->updateEntry($targetEntry);
        $this->indexer->updateEntry($sourceEntry);

        // Parse the backlinks tag
        $template = '{{ backlinks from="'.$targetEntry->id().'" }}{{ title }}{{ /backlinks }}';
        $result = (string) Antlers::parse($template);

        $this->assertStringContainsString('Source Page', $result);
    }

    public function test_backlinks_count_tag_returns_count()
    {
        // Create target entry
        $targetEntry = Entry::make()
            ->collection('pages')
            ->slug('counted')
            ->data(['title' => 'Counted Page']);
        $targetEntry->save();

        // Create two source entries
        $source1 = Entry::make()
            ->collection('pages')
            ->slug('source1')
            ->data([
                'title' => 'Source One',
                'content' => '[[Counted Page]]',
            ]);
        $source1->save();

        $source2 = Entry::make()
            ->collection('pages')
            ->slug('source2')
            ->data([
                'title' => 'Source Two',
                'content' => '[[Counted Page]]',
            ]);
        $source2->save();

        // Update index
        $this->indexer->updateEntry($targetEntry);
        $this->indexer->updateEntry($source1);
        $this->indexer->updateEntry($source2);

        $template = '{{ backlinks:count from="'.$targetEntry->id().'" }}';
        $result = (string) Antlers::parse($template);

        $this->assertEquals('2', trim($result));
    }

    public function test_backlinks_tag_handles_no_results()
    {
        $entry = Entry::make()
            ->collection('pages')
            ->slug('lonely')
            ->data(['title' => 'Lonely Page']);
        $entry->save();

        $this->indexer->updateEntry($entry);

        // Test that count returns 0 for entries with no backlinks
        $template = '{{ backlinks:count from="'.$entry->id().'" }}';
        $result = (string) Antlers::parse($template);

        $this->assertEquals('0', trim($result));
    }
}
