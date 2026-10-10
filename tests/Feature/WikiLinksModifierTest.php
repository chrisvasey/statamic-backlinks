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
        $this->modifier = new WikiLinks;

        // Create a test collection
        Collection::make('pages')->routes('/{slug}')->save();
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
        $this->assertStringContainsString('href="/about"', $result);
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

    public function test_renders_draft_page_as_span(): void
    {
        $entry = Entry::make()
            ->collection('pages')
            ->slug('draft')
            ->published(false)
            ->data(['title' => 'Draft Page']);
        $entry->save();

        $this->indexer->updateEntry($entry);

        $this->assertSame(
            '<span class="wiki-link-missing" data-title="Draft Page">Draft Page</span>',
            $this->modifier->index('[[Draft Page]]', [], [])
        );
    }

    public function test_renders_scheduled_page_as_span(): void
    {
        Collection::make('articles')->routes('/articles/{slug}')->dated(true)->futureDateBehavior('private')->save();

        $entry = Entry::make()
            ->collection('articles')
            ->slug('scheduled')
            ->date(now()->addDay())
            ->data(['title' => 'Scheduled Page']);
        $entry->save();

        $this->indexer->updateEntry($entry);

        $this->assertSame(
            '<span class="wiki-link-missing" data-title="Scheduled Page">Scheduled Page</span>',
            $this->modifier->index('[[Scheduled Page]]', [], [])
        );
    }

    public function test_renders_page_without_url_as_span(): void
    {
        Collection::make('unrouted')->save();

        $entry = Entry::make()
            ->collection('unrouted')
            ->slug('unrouted')
            ->data(['title' => 'Unrouted Page']);
        $entry->save();

        $this->indexer->updateEntry($entry);

        $this->assertSame(
            '<span class="wiki-link-missing" data-title="Unrouted Page">Unrouted Page</span>',
            $this->modifier->index('[[Unrouted Page]]', [], [])
        );
    }

    public function test_returns_content_without_wiki_links_unchanged(): void
    {
        $content = '<p>Regular content with <a href="/about">an existing link</a>.</p>';

        $this->assertSame($content, $this->modifier->index($content, [], []));
    }
}
