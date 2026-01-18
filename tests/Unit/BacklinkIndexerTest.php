<?php

namespace Chrisvasey\StatamicBacklinks\Tests\Unit;

use Chrisvasey\StatamicBacklinks\BacklinkIndexer;
use Chrisvasey\StatamicBacklinks\Tests\TestCase;

class BacklinkIndexerTest extends TestCase
{
    protected BacklinkIndexer $indexer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->indexer = new BacklinkIndexer();
    }

    public function test_extracts_simple_wiki_links()
    {
        $content = 'Check out [[Page One]] and [[Page Two]] for more info.';
        $links = $this->indexer->extractLinks($content);

        $this->assertCount(2, $links);
        $this->assertEquals('Page One', $links->get('Page One'));
        $this->assertEquals('Page Two', $links->get('Page Two'));
    }

    public function test_extracts_wiki_links_with_display_text()
    {
        $content = 'Visit [[Home Page|our homepage]] for details.';
        $links = $this->indexer->extractLinks($content);

        $this->assertCount(1, $links);
        $this->assertEquals('our homepage', $links->get('Home Page'));
    }

    public function test_extracts_mixed_wiki_links()
    {
        $content = 'See [[Simple Link]] and [[Another Page|custom text]] here.';
        $links = $this->indexer->extractLinks($content);

        $this->assertCount(2, $links);
        $this->assertEquals('Simple Link', $links->get('Simple Link'));
        $this->assertEquals('custom text', $links->get('Another Page'));
    }

    public function test_returns_empty_collection_for_no_links()
    {
        $content = 'This is just regular text without any links.';
        $links = $this->indexer->extractLinks($content);

        $this->assertCount(0, $links);
    }

    public function test_handles_whitespace_in_links()
    {
        $content = '[[  Spaced Title  ]] and [[Another|  Spaced Display  ]]';
        $links = $this->indexer->extractLinks($content);

        $this->assertCount(2, $links);
        $this->assertEquals('Spaced Title', $links->get('Spaced Title'));
        $this->assertEquals('Spaced Display', $links->get('Another'));
    }

    public function test_ignores_malformed_links()
    {
        $content = 'This [is not a link] and neither is [[incomplete';
        $links = $this->indexer->extractLinks($content);

        $this->assertCount(0, $links);
    }
}
