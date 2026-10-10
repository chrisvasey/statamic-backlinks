<?php

namespace Chrisvasey\StatamicBacklinks\Tests\Feature;

use Chrisvasey\StatamicBacklinks\BacklinkIndexer;
use Chrisvasey\StatamicBacklinks\Tests\TestCase;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\User;

class BacklinksControllerTest extends TestCase
{
    protected BacklinkIndexer $indexer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->indexer = app(BacklinkIndexer::class);

        Collection::make('pages')->routes('/{slug}')->save();
        Collection::make('articles')->routes('/articles/{slug}')->save();
    }

    protected function makeUser()
    {
        return User::make()
            ->email('test@example.com')
            ->makeSuper()
            ->save();
    }

    /**
     * @param  list<string>  $permissions
     */
    protected function makeEditor(array $permissions): UserContract
    {
        Role::path($this->fakeStacheDirectory.'/roles.yaml');
        Role::make('editor')->permissions(['access cp', ...$permissions])->save();

        return User::make()
            ->email('editor@example.com')
            ->assignRole('editor')
            ->save();
    }

    // -------------------------------------------------------------------------
    // Search endpoint tests
    // -------------------------------------------------------------------------

    public function test_search_returns_entries_matching_query()
    {
        $this->makeUser();

        Entry::make()->collection('pages')->slug('hello-world')->data(['title' => 'Hello World'])->save();
        Entry::make()->collection('pages')->slug('goodbye-world')->data(['title' => 'Goodbye World'])->save();
        Entry::make()->collection('pages')->slug('different')->data(['title' => 'Something Different'])->save();

        $response = $this->actingAs(User::findByEmail('test@example.com'))
            ->getJson(cp_route('backlinks.search', ['q' => 'World']));

        $response->assertOk();

        $data = $response->json();
        $this->assertCount(2, $data);

        $titles = collect($data)->pluck('title')->all();
        $this->assertContains('Hello World', $titles);
        $this->assertContains('Goodbye World', $titles);
        $this->assertNotContains('Something Different', $titles);
    }

    public function test_search_returns_all_entries_when_query_empty()
    {
        $this->makeUser();

        Entry::make()->collection('pages')->slug('page-one')->data(['title' => 'Page One'])->save();
        Entry::make()->collection('pages')->slug('page-two')->data(['title' => 'Page Two'])->save();
        Entry::make()->collection('pages')->slug('page-three')->data(['title' => 'Page Three'])->save();

        $response = $this->actingAs(User::findByEmail('test@example.com'))
            ->getJson(cp_route('backlinks.search'));

        $response->assertOk();

        $data = $response->json();
        $this->assertCount(3, $data);
    }

    public function test_search_respects_limit_parameter()
    {
        $this->makeUser();

        Entry::make()->collection('pages')->slug('page-1')->data(['title' => 'Page 1'])->save();
        Entry::make()->collection('pages')->slug('page-2')->data(['title' => 'Page 2'])->save();
        Entry::make()->collection('pages')->slug('page-3')->data(['title' => 'Page 3'])->save();
        Entry::make()->collection('pages')->slug('page-4')->data(['title' => 'Page 4'])->save();
        Entry::make()->collection('pages')->slug('page-5')->data(['title' => 'Page 5'])->save();

        $response = $this->actingAs(User::findByEmail('test@example.com'))
            ->getJson(cp_route('backlinks.search', ['limit' => 2]));

        $response->assertOk();

        $data = $response->json();
        $this->assertCount(2, $data);
    }

    public function test_search_caps_limit_at_50()
    {
        $this->makeUser();

        // Create 55 entries
        for ($i = 1; $i <= 55; $i++) {
            Entry::make()->collection('pages')->slug("page-{$i}")->data(['title' => "Page {$i}"])->save();
        }

        $response = $this->actingAs(User::findByEmail('test@example.com'))
            ->getJson(cp_route('backlinks.search', ['limit' => 100]));

        $response->assertOk();

        $data = $response->json();
        $this->assertCount(50, $data);
    }

    public function test_search_returns_correct_structure()
    {
        $this->makeUser();

        $entry = Entry::make()
            ->collection('pages')
            ->slug('test-page')
            ->data(['title' => 'Test Page']);
        $entry->save();

        $response = $this->actingAs(User::findByEmail('test@example.com'))
            ->getJson(cp_route('backlinks.search', ['q' => 'Test']));

        $response->assertOk();

        $data = $response->json();
        $this->assertCount(1, $data);

        $result = $data[0];
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('collection', $result);
        $this->assertArrayHasKey('collection_title', $result);
        $this->assertArrayHasKey('url', $result);
        $this->assertArrayHasKey('edit_url', $result);

        $this->assertEquals('Test Page', $result['title']);
        $this->assertEquals('pages', $result['collection']);
    }

    public function test_search_filters_restricted_entries_before_applying_the_limit(): void
    {
        $editor = $this->makeEditor(['view pages entries']);

        Entry::make()->collection('articles')->slug('hidden')->data(['title' => 'A hidden entry'])->save();
        $allowed = Entry::make()->collection('pages')->slug('allowed')->data(['title' => 'B allowed entry']);
        $allowed->save();

        $response = $this->actingAs($editor)
            ->getJson(cp_route('backlinks.search', ['q' => 'entry', 'limit' => 1]));

        $response->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $allowed->id());
    }

    public function test_search_returns_no_entries_without_view_permission(): void
    {
        $editor = $this->makeEditor([]);
        Entry::make()->collection('pages')->slug('hidden')->data(['title' => 'Hidden'])->save();

        $this->actingAs($editor)
            ->getJson(cp_route('backlinks.search'))
            ->assertOk()
            ->assertExactJson([]);
    }

    // -------------------------------------------------------------------------
    // Create endpoint tests
    // -------------------------------------------------------------------------

    public function test_create_makes_new_entry()
    {
        $this->makeUser();

        $response = $this->actingAs(User::findByEmail('test@example.com'))
            ->postJson(cp_route('backlinks.create'), [
                'title' => 'New Wiki Page',
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('entry.title', 'New Wiki Page');

        // Verify entry was created
        $entryId = $response->json('entry.id');
        $entry = Entry::find($entryId);
        $this->assertNotNull($entry);
        $this->assertEquals('New Wiki Page', $entry->get('title'));
        $this->assertEquals('new-wiki-page', $entry->slug());
    }

    public function test_create_uses_same_collection_as_source()
    {
        $this->makeUser();

        // Create a source entry in the articles collection
        $sourceEntry = Entry::make()
            ->collection('articles')
            ->slug('source-article')
            ->data(['title' => 'Source Article']);
        $sourceEntry->save();

        $response = $this->actingAs(User::findByEmail('test@example.com'))
            ->postJson(cp_route('backlinks.create'), [
                'title' => 'Linked Article',
                'source_entry_id' => $sourceEntry->id(),
            ]);

        $response->assertOk();

        // Verify the new entry is in the same collection
        $entryId = $response->json('entry.id');
        $entry = Entry::find($entryId);
        $this->assertEquals('articles', $entry->collection()->handle());
    }

    public function test_create_uses_default_collection_when_no_source()
    {
        $this->makeUser();

        // Ensure default collection is 'pages' via config
        config(['backlinks.creation.default_collection' => 'pages']);

        $response = $this->actingAs(User::findByEmail('test@example.com'))
            ->postJson(cp_route('backlinks.create'), [
                'title' => 'Default Collection Page',
            ]);

        $response->assertOk();

        $entryId = $response->json('entry.id');
        $entry = Entry::find($entryId);
        $this->assertEquals('pages', $entry->collection()->handle());
    }

    public function test_create_validates_title_required()
    {
        $this->makeUser();

        $response = $this->actingAs(User::findByEmail('test@example.com'))
            ->postJson(cp_route('backlinks.create'), []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['title']);
    }

    public function test_create_respects_published_config()
    {
        $this->makeUser();

        // Set to unpublished by default
        config(['backlinks.creation.default_published' => false]);

        $response = $this->actingAs(User::findByEmail('test@example.com'))
            ->postJson(cp_route('backlinks.create'), [
                'title' => 'Unpublished Page',
            ]);

        $response->assertOk();

        $entryId = $response->json('entry.id');
        $entry = Entry::find($entryId);
        $this->assertFalse($entry->published());
    }

    public function test_create_requires_permission_for_the_destination_collection(): void
    {
        $editor = $this->makeEditor(['view pages entries']);

        $this->actingAs($editor)
            ->postJson(cp_route('backlinks.create'), ['title' => 'Forbidden Page'])
            ->assertForbidden();

        $this->assertCount(0, Entry::all());
    }

    public function test_create_allows_an_editor_with_collection_permission(): void
    {
        $editor = $this->makeEditor(['view pages entries', 'create pages entries']);

        $response = $this->actingAs($editor)
            ->postJson(cp_route('backlinks.create'), ['title' => 'Allowed Page']);

        $response->assertOk()->assertJsonPath('entry.title', 'Allowed Page');
        $this->assertFalse(Entry::find($response->json('entry.id'))->published());
    }

    public function test_create_requires_view_permission_for_the_source_entry(): void
    {
        $editor = $this->makeEditor(['create articles entries']);
        $source = Entry::make()->collection('articles')->slug('source')->data(['title' => 'Restricted Source']);
        $source->save();

        $this->actingAs($editor)
            ->postJson(cp_route('backlinks.create'), [
                'title' => 'Forbidden Page',
                'source_entry_id' => $source->id(),
            ])
            ->assertForbidden();

        $this->assertCount(1, Entry::all());
    }

    public function test_create_checks_permissions_for_the_source_collection(): void
    {
        $editor = $this->makeEditor(['view articles entries', 'create pages entries']);
        $source = Entry::make()->collection('articles')->slug('source')->data(['title' => 'Visible Source']);
        $source->save();

        $this->actingAs($editor)
            ->postJson(cp_route('backlinks.create'), [
                'title' => 'Forbidden Article',
                'source_entry_id' => $source->id(),
            ])
            ->assertForbidden();

        $this->assertCount(1, Entry::all());
    }

    public function test_create_keeps_entries_as_drafts_without_publish_permission(): void
    {
        config(['backlinks.creation.default_published' => true]);
        $editor = $this->makeEditor(['create pages entries']);

        $response = $this->actingAs($editor)
            ->postJson(cp_route('backlinks.create'), ['title' => 'Draft Page']);

        $response->assertOk();
        $this->assertFalse(Entry::find($response->json('entry.id'))->published());
    }

    public function test_create_honors_published_config_with_publish_permission(): void
    {
        config(['backlinks.creation.default_published' => true]);
        $editor = $this->makeEditor(['create pages entries', 'publish pages entries']);

        $response = $this->actingAs($editor)
            ->postJson(cp_route('backlinks.create'), ['title' => 'Published Page']);

        $response->assertOk();
        $this->assertTrue(Entry::find($response->json('entry.id'))->published());
    }

    // -------------------------------------------------------------------------
    // Show endpoint tests
    // -------------------------------------------------------------------------

    public function test_show_returns_backlinks_for_entry()
    {
        $this->makeUser();

        // Create target entry
        $targetEntry = Entry::make()
            ->collection('pages')
            ->slug('target-page')
            ->data(['title' => 'Target Page']);
        $targetEntry->save();

        // Create source entry that links to target
        $sourceEntry = Entry::make()
            ->collection('pages')
            ->slug('source-page')
            ->data([
                'title' => 'Source Page',
                'content' => 'Check out [[Target Page]] for more info.',
            ]);
        $sourceEntry->save();

        // Update the index
        $this->indexer->updateEntry($targetEntry);
        $this->indexer->updateEntry($sourceEntry);

        $response = $this->actingAs(User::findByEmail('test@example.com'))
            ->getJson(cp_route('backlinks.show', ['entry' => $targetEntry->id()]));

        $response->assertOk();
        $response->assertJsonPath('count', 1);

        $backlinks = $response->json('backlinks');
        $this->assertCount(1, $backlinks);
        $this->assertEquals('Source Page', $backlinks[0]['title']);
        $this->assertEquals('pages', $backlinks[0]['collection']);
    }

    public function test_show_returns_404_for_missing_entry()
    {
        $this->makeUser();

        // Statamic's route model binding throws NotFoundHttpException for missing entries
        $response = $this->actingAs(User::findByEmail('test@example.com'))
            ->getJson(cp_route('backlinks.show', ['entry' => 'non-existent-id']));

        $response->assertStatus(404);
    }

    public function test_show_returns_empty_when_no_backlinks()
    {
        $this->makeUser();

        $entry = Entry::make()
            ->collection('pages')
            ->slug('lonely-page')
            ->data(['title' => 'Lonely Page']);
        $entry->save();

        $this->indexer->updateEntry($entry);

        $response = $this->actingAs(User::findByEmail('test@example.com'))
            ->getJson(cp_route('backlinks.show', ['entry' => $entry->id()]));

        $response->assertOk();
        $response->assertJsonPath('count', 0);
        $response->assertJsonPath('backlinks', []);
    }

    public function test_show_returns_correct_structure()
    {
        $this->makeUser();

        $targetEntry = Entry::make()
            ->collection('pages')
            ->slug('target')
            ->data(['title' => 'Target']);
        $targetEntry->save();

        $sourceEntry = Entry::make()
            ->collection('pages')
            ->slug('source')
            ->data([
                'title' => 'Source',
                'content' => '[[Target]]',
            ]);
        $sourceEntry->save();

        $this->indexer->updateEntry($targetEntry);
        $this->indexer->updateEntry($sourceEntry);

        $response = $this->actingAs(User::findByEmail('test@example.com'))
            ->getJson(cp_route('backlinks.show', ['entry' => $targetEntry->id()]));

        $response->assertOk();
        $response->assertJsonStructure([
            'count',
            'backlinks' => [
                '*' => ['id', 'title', 'url', 'edit_url', 'collection'],
            ],
        ]);
    }

    public function test_show_requires_view_permission_for_the_target_entry(): void
    {
        $editor = $this->makeEditor(['view pages entries']);
        $target = Entry::make()->collection('articles')->slug('hidden')->data(['title' => 'Hidden']);
        $target->save();

        $this->actingAs($editor)
            ->getJson(cp_route('backlinks.show', ['entry' => $target->id()]))
            ->assertForbidden();
    }

    public function test_show_omits_restricted_backlinks_and_their_count(): void
    {
        $editor = $this->makeEditor(['view pages entries']);
        $target = Entry::make()->collection('pages')->slug('target')->data(['title' => 'Target']);
        $target->save();
        $hidden = Entry::make()->collection('articles')->slug('hidden')->data(['title' => 'Hidden', 'content' => '[[Target]]']);
        $hidden->save();
        $allowed = Entry::make()->collection('pages')->slug('allowed')->data(['title' => 'Allowed', 'content' => '[[Target]]']);
        $allowed->save();

        foreach ([$target, $hidden, $allowed] as $entry) {
            $this->indexer->updateEntry($entry);
        }

        $this->actingAs($editor)
            ->getJson(cp_route('backlinks.show', ['entry' => $target->id()]))
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonCount(1, 'backlinks')
            ->assertJsonPath('backlinks.0.id', $allowed->id());
    }
}
