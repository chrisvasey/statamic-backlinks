import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as Vue from 'vue';

const source = readFileSync(new URL('../../resources/js/components/BacklinksSidebar.vue', import.meta.url), 'utf8');
const compiled = compileScript(parse(source).descriptor, {
    id: 'backlinks-sidebar-test',
    inlineTemplate: true,
    templateOptions: { compilerOptions: { hoistStatic: false } },
}).content.replace(
    /import\s*\{([\s\S]*?)\}\s*from\s*(['"])(vue|@statamic\/cms\/ui)\2;?/g,
    (_, names, quote, module) => `const {${names.replace(/\bas\b/g, ':')}} = ${module === 'vue' ? 'Vue' : 'ui'};`,
).replace('export default', 'return');

class HostNode {
    constructor(tag, text = '') {
        this.tag = tag;
        this.text = text;
        this.children = [];
        this.parentNode = null;
        this.props = {};
        Vue.markRaw(this);
    }

    get parentElement() { return this.parentNode; }
    get textContent() { return this.text + this.children.map(child => child.textContent).join(''); }
    querySelector(tag) { return this.children.find(child => child.tag === tag) ?? this.children.map(child => child.querySelector(tag)).find(Boolean) ?? null; }
}

function remove(node) {
    if (node.parentNode) {
        node.parentNode.children.splice(node.parentNode.children.indexOf(node), 1);
        node.parentNode = null;
    }
}

function insert(node, parent, anchor = null) {
    remove(node);
    const index = parent.children.indexOf(anchor);
    parent.children.splice(index < 0 ? parent.children.length : index, 0, node);
    node.parentNode = parent;
}

const renderer = Vue.createRenderer({
    createElement: tag => new HostNode(tag),
    createText: text => new HostNode('#text', text),
    createComment: text => new HostNode('#comment'),
    insert,
    remove,
    parentNode: node => node.parentNode,
    nextSibling: node => node.parentNode?.children[node.parentNode.children.indexOf(node) + 1] ?? null,
    setText: (node, text) => { node.text = text; },
    setElementText: (node, text) => { node.text = text; node.children = []; },
    patchProp: (node, key, previous, next) => { node.props[key] = next; },
    querySelector: () => null,
});

async function flush() {
    await new Promise(setImmediate);
    await Vue.nextTick();
}

function mountSidebar(t, fetch) {
    const originals = { fetch: globalThis.fetch, cp_url: globalThis.cp_url, MutationObserver: globalThis.MutationObserver, Statamic: globalThis.Statamic, error: console.error };
    const reference = Vue.ref('entry::target');
    const observers = [];
    const errors = [];
    const savedListeners = [];

    globalThis.fetch = fetch;
    globalThis.cp_url = path => `/custom-cp/${path}`;
    globalThis.Statamic = { $events: {
        $on: (name, callback) => { assert.equal(name, 'backlinks-entry-saved'); savedListeners.push(callback); },
        $off: (name, callback) => { assert.equal(name, 'backlinks-entry-saved'); savedListeners.splice(savedListeners.indexOf(callback), 1); },
    } };
    console.error = (...args) => errors.push(args);
    globalThis.MutationObserver = class {
        constructor(callback) { this.callback = callback; this.disconnected = false; observers.push(this); }
        observe(target, options) { this.target = target; this.options = options; }
        disconnect() { this.disconnected = true; }
    };

    const component = new Function('Vue', 'ui', compiled)(Vue, {
        Card: (props, { slots }) => Vue.h('article', slots.default?.()),
        injectPublishContext: () => ({ reference }),
    });
    const root = new HostNode('root');
    const app = renderer.createApp({
        render: () => Vue.h('section', [Vue.h(component), Vue.h('div', { id: 'publish-tabs' })]),
    });
    app.mount(root);
    t.after(() => {
        app.unmount();
        globalThis.fetch = originals.fetch;
        globalThis.cp_url = originals.cp_url;
        globalThis.MutationObserver = originals.MutationObserver;
        globalThis.Statamic = originals.Statamic;
        console.error = originals.error;
    });

    return { app, root, form: root.children[0], reference, observers, errors, savedListeners };
}

function response(backlinks = []) {
    return { ok: true, json: async () => ({ backlinks, count: backlinks.length }) };
}

test('addon mounts the registered sidebar for entry publish containers only', () => {
    const callbacks = new Map();
    const hooks = new Map();
    const registered = new Map();
    const mounted = [];
    const events = [];
    const component = {};
    const addon = readFileSync(new URL('../../resources/js/addon.js', import.meta.url), 'utf8').replace(/^import .+;\n/gm, '');
    new Function('Statamic', 'BacklinksSidebar', addon)({
        booting: callback => callback(),
        configuring: () => {},
        $components: { register: (name, value) => registered.set(name, value) },
        $events: { $on: (name, callback) => callbacks.set(name, callback), $emit: (...args) => events.push(args) },
        $hooks: { on: (name, callback) => hooks.set(name, callback) },
        $bard: { addExtension: () => {} },
    }, component);

    assert.equal(registered.get('backlinks-sidebar'), component);
    const created = callbacks.get('publish-container-created');
    for (const reference of ['entry::target', 'term::category', null]) {
        created({ reference: Vue.ref(reference), pushComponent: (...args) => mounted.push(args) });
    }
    assert.deepEqual(mounted, [['backlinks-sidebar', { props: {} }]]);

    let resolved = false;
    hooks.get('entry.saved')(() => { resolved = true; }, assert.fail, {
        reference: 'entry::initial-localization',
        response: { data: { data: { reference: 'entry::saved-localization' } } },
    });
    assert.deepEqual(events, [['backlinks-entry-saved', 'entry::saved-localization']]);
    assert.equal(resolved, true);
});

test('sidebar follows its form sidebar and renders empty, linked, and error states', async t => {
    const requests = [];
    const responses = [response(), response([{ id: 'source', title: 'Source Page', collection: 'pages', edit_url: '/custom-cp/source/edit' }]), { ok: false }];
    const { root, form, reference, observers, errors } = mountSidebar(t, async url => {
        requests.push(url);
        return responses.shift();
    });
    await flush();

    const anchor = form.children[0];
    const tabs = form.children[1];
    const [observer] = observers;
    assert.equal(observer.target, form);
    assert.deepEqual(observer.options, { childList: true, subtree: true });
    assert.match(anchor.textContent, /No pages link to this entry\./);
    assert.equal(root.querySelector('a'), null);
    assert.deepEqual(requests, ['/custom-cp/backlinks/target']);

    const aside = new HostNode('aside');
    insert(aside, tabs);
    observer.callback();
    await flush();
    assert.ok(aside.querySelector('article'));
    assert.equal(anchor.querySelector('article'), null);

    remove(aside);
    observer.callback();
    await flush();
    assert.ok(anchor.querySelector('article'));
    assert.equal(aside.querySelector('article'), null);

    reference.value = 'entry::linked';
    await flush();
    assert.equal(root.querySelector('a').props.href, '/custom-cp/source/edit');
    assert.match(root.querySelector('a').textContent, /Source Page/);

    reference.value = 'entry::error';
    await flush();
    assert.match(root.textContent, /Failed to fetch backlinks/);
    assert.equal(root.querySelector('a'), null);
    assert.equal(errors.length, 1);
});

test('entry changes cancel stale requests and unmount disconnects the observer', async t => {
    const requests = [];
    const { app, root, reference, observers } = mountSidebar(t, (url, options) => new Promise(resolve => {
        requests.push({ url, signal: options.signal, resolve });
    }));

    reference.value = 'entry::next/entry';
    await flush();
    assert.equal(requests[0].signal.aborted, true);
    assert.equal(requests[1].url, '/custom-cp/backlinks/next%2Fentry');

    requests[1].resolve(response([{ id: 'new', title: 'Current Page', collection: 'pages', edit_url: '/current/edit' }]));
    await flush();
    requests[0].resolve(response([{ id: 'old', title: 'Stale Page', collection: 'pages', edit_url: '/stale/edit' }]));
    await flush();
    assert.match(root.textContent, /Current Page/);
    assert.doesNotMatch(root.textContent, /Stale Page/);

    reference.value = null;
    await flush();
    assert.equal(requests.length, 2);
    assert.equal(root.querySelector('article'), null);

    app.unmount();
    assert.equal(observers[0].disconnected, true);
});

test('saving the current entry refreshes backlinks and removes its listener on unmount', async t => {
    const requests = [];
    const responses = [response([{ id: 'source', title: 'Source Page', collection: 'pages', edit_url: '/source/edit' }]), response()];
    const { app, root, savedListeners } = mountSidebar(t, async url => {
        requests.push(url);
        return responses.shift();
    });
    await flush();
    assert.ok(root.querySelector('a'));
    assert.equal(savedListeners.length, 1);

    const saved = reference => savedListeners.forEach(callback => callback(reference));

    saved('entry::other');
    await flush();
    assert.equal(requests.length, 1);

    saved('entry::target');
    await flush();
    assert.deepEqual(requests, ['/custom-cp/backlinks/target', '/custom-cp/backlinks/target']);
    assert.equal(root.querySelector('a'), null);
    assert.match(root.textContent, /No pages link to this entry\./);

    const unrelatedListener = () => {};
    savedListeners.push(unrelatedListener);
    app.unmount();
    assert.deepEqual(savedListeners, [unrelatedListener]);
});
