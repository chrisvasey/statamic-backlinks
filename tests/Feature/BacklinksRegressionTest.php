<?php

namespace Chrisvasey\StatamicBacklinks\Tests\Feature;

use Chrisvasey\StatamicBacklinks\BacklinkIndexer;
use Chrisvasey\StatamicBacklinks\Modifiers\WikiLinks;
use Chrisvasey\StatamicBacklinks\Tests\TestCase;
use Chrisvasey\StatamicBacklinks\Widgets\BacklinksWidget;
use Statamic\Entries\Entry as EntryContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\User;

class BacklinksRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['backlinks.storage' => 'cache']);
        app(BacklinkIndexer::class)->clear();
        Collection::make('pages')->routes('/{slug}')->save();
        Collection::make('articles')->routes('/articles/{slug}')->save();
    }

    /** @param array<string, mixed> $data */
    protected function entry(string $title, string $collection = 'pages', array $data = []): EntryContract
    {
        $entry = Entry::make()->collection($collection)->slug(str($title)->slug()->toString())->data(['title' => $title, ...$data]);
        $entry->save();

        return $entry;
    }

    public function test_html_entities_inline_formatting_and_code_are_handled_consistently(): void
    {
        $target = $this->entry('About Us');
        $ampersand = $this->entry('R&D');
        $html = '<p>[[About <strong>Us</strong>|R&amp;D]] and [[R&amp;D]]</p><code>[[About Us]]</code><a href="/elsewhere">[[About Us]]</a>';
        $result = (new WikiLinks)->index($html, [], []);
        $this->assertStringContainsString('href="/about-us">R&amp;D</a>', $result);
        $this->assertStringContainsString('href="'.$ampersand->url().'">R&amp;D</a>', $result);
        $this->assertStringNotContainsString('&amp;amp;', $result);
        $this->assertStringContainsString('<code>[[About Us]]</code>', $result);
        $this->assertStringContainsString('<a href="/elsewhere">[[About Us]]</a>', $result);

        $source = $this->entry('Source', data: ['body' => [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => '[[About '],
            ['type' => 'text', 'text' => 'Us]]', 'marks' => [['type' => 'bold']]],
        ]]]]);
        $this->assertSame([$source->id()], app(BacklinkIndexer::class)->getBacklinksFor($target)->map->id()->all());
        $this->assertCount(0, app(BacklinkIndexer::class)->extractLinks('`[[About Us]]` [See [[About Us]]](https://example.com)'));
    }

    public function test_markdown_code_blocks_and_bard_literal_punctuation_match_rendering(): void
    {
        $target = $this->entry('Target');
        $this->entry('Code Source', data: ['content' => "```\n[[Target]]\n```"]);
        $source = $this->entry('Bard Source', data: ['body' => [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => '`[[Target]]`'],
        ]]]]);
        $this->assertSame([$source->id()], app(BacklinkIndexer::class)->getBacklinksFor($target)->map->id()->all());
    }

    public function test_multiple_formatted_and_unicode_links_do_not_corrupt_adjacent_text(): void
    {
        $this->entry('Café');
        $result = (new WikiLinks)->index('<p>Before [[Café]] middle [[Ca<strong>fé</strong>]] after [[Café|tea &amp; cake]].</p>', [], []);
        $this->assertSame(3, substr_count($result, '<a '));
        $this->assertStringContainsString('Before ', $result);
        $this->assertStringContainsString(' middle ', $result);
        $this->assertStringContainsString(' after ', $result);
        $this->assertStringContainsString('tea &amp; cake</a>.', $result);
    }

    public function test_collection_restrictions_apply_to_rebuild_saves_resolution_and_search(): void
    {
        config(['backlinks.scan_collections' => ['pages'], 'backlinks.linkable_collections' => ['articles']]);
        $target = $this->entry('Target', 'articles');
        $source = $this->entry('Source', data: ['content' => '[[Target]]']);
        $excluded = $this->entry('Excluded', 'articles', ['content' => '[[Target]]']);
        $indexer = app(BacklinkIndexer::class);
        $indexer->rebuild();
        $this->assertSame([$source->id()], $indexer->getBacklinksFor($target)->map->id()->all());
        $excluded->set('content', '[[Target]] again')->save();
        $this->assertSame([$source->id()], $indexer->getBacklinksFor($target)->map->id()->all());
        $this->assertNull($indexer->resolveTitle('Source'));
        $this->assertSame($target->id(), $indexer->resolveTitle('Target')->id());
        $this->actingAs(User::make()->email('super@example.com')->makeSuper()->save())
            ->getJson(cp_route('backlinks.search', ['q' => 'Source']))->assertExactJson([]);
    }

    public function test_duplicate_titles_only_count_for_the_resolved_target(): void
    {
        $first = $this->entry('About');
        $second = $this->entry('About', 'articles');
        $source = $this->entry('Source', data: ['content' => '[[About]]']);
        $indexer = app(BacklinkIndexer::class);
        $this->assertSame($second->id(), $indexer->resolveTitle('About')->id());
        $this->assertCount(0, $indexer->getBacklinksFor($first));
        $this->assertSame([$source->id()], $indexer->getBacklinksFor($second)->map->id()->all());
    }

    public function test_stale_index_instances_preserve_other_writers_for_file_and_cache_storage(): void
    {
        $a = $this->entry('A');
        $b = $this->entry('B');
        foreach (['file', 'cache'] as $storage) {
            config(['backlinks.storage' => $storage, 'backlinks.storage_path' => $this->fakeStacheDirectory.'/backlinks.json']);
            (new BacklinkIndexer)->clear();
            $first = new BacklinkIndexer;
            $second = new BacklinkIndexer;
            $first->getIndex();
            $second->getIndex();
            $first->updateEntry($a);
            $second->updateEntry($b);
            $reader = new BacklinkIndexer;
            $this->assertSame($a->id(), $reader->resolveTitle('A')?->id());
            $this->assertSame($b->id(), $reader->resolveTitle('B')?->id());
        }
    }

    public function test_widget_lists_incoming_targets_and_filters_restricted_rows_and_counts_before_limit(): void
    {
        Role::path($this->fakeStacheDirectory.'/roles.yaml');
        Role::make('editor')->permissions(['access cp', 'view pages entries'])->save();
        $this->actingAs(User::make()->email('editor@example.com')->assignRole('editor')->save());
        $hidden = $this->entry('Hidden Target', 'articles');
        $visible = $this->entry('Visible Target');
        $this->entry('Visible Source', data: ['content' => '[[Hidden Target]] [[Visible Target]]']);
        $this->entry('Hidden Source', 'articles', ['content' => '[[Visible Target]]']);
        $widget = new BacklinksWidget;
        $widget->setConfig(['limit' => 1]);
        $result = $widget->html();
        $this->assertStringContainsString('Visible Target', $result);
        $this->assertStringContainsString('1 incoming', $result);
        $this->assertStringNotContainsString('Hidden', $result);
        $this->assertStringNotContainsString('Visible Source', $result);
    }

    public function test_exact_search_ignores_substring_limit_and_accepts_trimmed_case(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->entry('A About '.$i);
        }
        $target = $this->entry('About');
        $this->actingAs(User::make()->email('super@example.com')->makeSuper()->save())
            ->getJson(cp_route('backlinks.search', ['q' => ' about ', 'exact' => 1, 'limit' => 1]))
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $target->id());
    }

    public function test_creation_rejects_colliding_urls_and_preserves_the_original(): void
    {
        $original = $this->entry('New Page', data: ['content' => 'Keep me']);
        $this->actingAs(User::make()->email('super@example.com')->makeSuper()->save())
            ->postJson(cp_route('backlinks.create'), ['title' => 'New-Page'])
            ->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->assertCount(1, Entry::all());
        $this->assertSame('Keep me', Entry::find($original->id())->get('content'));
    }
}
