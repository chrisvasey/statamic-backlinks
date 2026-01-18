<?php

namespace Chrisvasey\StatamicBacklinks\Tests\Feature;

use Chrisvasey\StatamicBacklinks\BacklinkIndexer;
use Chrisvasey\StatamicBacklinks\Modifiers\WikiLinks;
use Chrisvasey\StatamicBacklinks\Tests\TestCase;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

class WikiLinksModifierTest extends TestCase
{
    protected BacklinkIndexer $indexer;

    protected WikiLinks $modifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->indexer = app(BacklinkIndexer::class);
        $this->modifier = new WikiLinks();

        // Create a test collection
        Collection::make('pages')->save();
    }

    public function test_renders_existing_page_as_link()
    {
        // Create an entry that can be linked to
        $entry = Entry::make()
            ->collection('pages')
            ->slug('about')
            ->data(['title' => 'About Us']);
        $entry->save();

        // Update the index
        $this->indexer->updateEntry($entry);

        $content = 'Visit [[About Us]] for more information.';
        $result = $this->modifier->index($content, [], []);

        $this->assertStringContainsString('class="wiki-link"', $result);
        $this->assertStringContainsString('About Us</a>', $result);
        $this->assertStringContainsString('href="', $result);
    }

    public function test_renders_missing_page_as_span()
    {
        $content = 'Visit [[Nonexistent Page]] for more information.';
        $result = $this->modifier->index($content, [], []);

        $this->assertStringContainsString('class="wiki-link-missing"', $result);
        $this->assertStringContainsString('data-title="Nonexistent Page"', $result);
        $this->assertStringContainsString('Nonexistent Page</span>', $result);
    }

    public function test_handles_custom_display_text()
    {
        // Create an entry
        $entry = Entry::make()
            ->collection('pages')
            ->slug('home')
            ->data(['title' => 'Home Page']);
        $entry->save();

        $this->indexer->updateEntry($entry);

        $content = 'Visit [[Home Page|our homepage]] now.';
        $result = $this->modifier->index($content, [], []);

        $this->assertStringContainsString('our homepage</a>', $result);
    }

    public function test_returns_non_string_values_unchanged()
    {
        $array = ['key' => 'value'];
        $result = $this->modifier->index($array, [], []);

        $this->assertEquals($array, $result);
    }
}
