import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
const { attachMarkdownWikiLinks } = await import('data:text/javascript;base64,' + Buffer.from(readFileSync(new URL('../../resources/js/extensions/MarkdownWikiLinks.js', import.meta.url), 'utf8')).toString('base64'));

function markdownEditor(text = '[[Ab]]', ch = 4) {
    const handlers = new Map();
    let cursor = { line: 0, ch };
    return {
        handlers, text, readonly: false,
        getCursor: () => cursor,
        getLine() { return this.text; },
        getOption() { return this.readonly; },
        somethingSelected: () => false,
        cursorCoords: () => ({ top: 10, bottom: 30, left: 20, right: 20 }),
        on: (event, callback) => handlers.set(event, callback),
        off: event => handlers.delete(event),
        replaceRange(value, from, to) { this.text = this.text.slice(0, from.ch) + value + this.text.slice(to.ch); },
        setCursor(value) { cursor = value; },
        focus() {},
    };
}

test('Markdown narrows search, inserts once around auto-closing brackets, and removes listeners', () => {
    const editor = markdownEditor();
    const updates = [];
    let closes = 0;
    const detach = attachMarkdownWikiLinks(editor, { update: value => updates.push(value), close: () => closes++, onKeyDown: () => false });
    editor.handlers.get('change')();
    assert.equal(updates[0].query, 'Ab');
    updates[0].insert('About');
    assert.equal(editor.text, '[[About]]');
    assert.deepEqual(editor.getCursor(), {line: 0, ch: 9});
    detach();
    assert.equal(editor.handlers.size, 0);
    assert.ok(closes);
});

test('Markdown Escape stays dismissed and read-only fields never search', () => {
    const editor = markdownEditor();
    let searches = 0;
    let prevented = false;
    attachMarkdownWikiLinks(editor, {update: () => searches++, close() {}, onKeyDown: () => false});
    editor.handlers.get('change')();
    editor.handlers.get('keydown')(editor, {key: 'Escape', preventDefault: () => {prevented = true;}, stopPropagation() {}});
    editor.handlers.get('cursorActivity')();
    assert.equal(searches, 1);
    assert.equal(prevented, true);
    editor.readonly = true;
    editor.text = '[[New';
    editor.handlers.get('change')();
    assert.equal(searches, 1);
});

function menuHarness() {
    const requests = [];
    const mounted = [];
    const errors = [];
    let popups = 0;
    const source = readFileSync(new URL('../../resources/js/WikiLinkMenu.js', import.meta.url), 'utf8').replace(/^import .*;\n/gm, '').replace(/export /g, '');
    const api = new Function('createApp', 'h', 'reactive', 'tippy', 'WikiLinkDropdown', 'fetch', 'cp_url', 'Statamic', 'document', `${source}; return {createWikiLinkMenu, sourceEntryId, createEntry};`)(
        component => ({mount: () => mounted.push(component.render()), unmount() {}}),
        (component, props) => props,
        value => value,
        () => {popups++; return {destroy: () => popups--, setProps() {}};},
        {},
        (url, options) => new Promise(resolve => requests.push({url, options, resolve})),
        path => `/custom-cp/${path}`,
        {$config: {get: () => 'csrf'}, $toast: {error: message => errors.push(message)}},
        {body: {}, createElement: () => ({})},
    );
    return { ...api, requests, mounted, errors, popups: () => popups };
}
const ok = data => ({ok: true, json: async () => data});

test('closing or superseding a pending search prevents stale dropdowns', async () => {
    const harness = menuHarness();
    const menu = harness.createWikiLinkMenu(() => 'source');
    const first = menu.update({query: 'old', clientRect() {}, insert() {}});
    menu.close();
    harness.requests[0].resolve(ok([{title: 'Old'}]));
    await first;
    assert.equal(harness.popups(), 0);
    const second = menu.update({query: 'slow', clientRect() {}, insert() {}});
    const third = menu.update({query: 'new', clientRect() {}, insert() {}});
    harness.requests[2].resolve(ok([{title: 'New'}]));
    await third;
    harness.requests[1].resolve(ok([{title: 'Slow'}]));
    await second;
    assert.equal(harness.mounted.length, 1);
    assert.equal(harness.mounted[0].query, 'new');
    assert.equal(harness.requests[1].options.signal.aborted, true);
    assert.match(harness.requests[2].url, /^\/custom-cp\/backlinks\/search/);
});

test('Create uses the source publish reference and inserts only after successful creation', async () => {
    const harness = menuHarness();
    const source = () => harness.sourceEntryId({reference: {value: 'entry::source-id'}});
    const menu = harness.createWikiLinkMenu(source);
    const inserted = [];
    const search = menu.update({query: 'New', clientRect() {}, insert: title => inserted.push(title)});
    harness.requests[0].resolve(ok([]));
    await search;
    const create = harness.mounted[0].command({title: 'New', isNew: true});
    assert.equal(inserted.length, 0);
    assert.equal(harness.requests[1].url, '/custom-cp/backlinks/create');
    assert.deepEqual(JSON.parse(harness.requests[1].options.body), {title: 'New', source_entry_id: 'source-id'});
    harness.requests[1].resolve(ok({entry: {title: 'New'}}));
    await create;
    assert.deepEqual(inserted, ['New']);
    assert.equal(harness.popups(), 0);
});
