import { createApp, h, reactive } from 'vue';
import tippy from 'tippy.js';
import WikiLinkDropdown from './components/WikiLinkDropdown.vue';

export function sourceEntryId(context) {
    const reference = context?.reference?.value ?? context?.reference;
    return reference?.startsWith('entry::') ? reference.slice(7) : null;
}

export async function createEntry(title, source) {
    const response = await fetch(cp_url('backlinks/create'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ title, source_entry_id: source() }),
    });
    const result = await response.json();
    if (!response.ok) throw new Error(Object.values(result.errors ?? {}).flat()[0] ?? result.message ?? 'Could not create page.');
    return result.entry;
}

export async function openWikiLink(title, source) {
    title = title.split('|')[0].trim();
    const tab = window.open('', '_blank');
    if (tab) tab.opener = null;
    try {
        const response = await fetch(cp_url(`backlinks/search?q=${encodeURIComponent(title)}&exact=1`));
        if (!response.ok) throw new Error('Could not find page.');
        const entries = await response.json();
        const entry = entries[0] ?? await createEntry(title, source);
        if (tab) tab.location.href = entry.edit_url;
    } catch (error) {
        tab?.close();
        Statamic.$toast.error(error.message);
    }
}

export function createWikiLinkMenu(source) {
    let app, popup, component, request;
    let generation = 0;
    let selecting = false;
    const state = reactive({ items: [], query: '', command: null });

    function close() {
        generation++;
        request?.abort();
        popup?.destroy();
        app?.unmount();
        app = popup = component = null;
    }

    async function update({ query, clientRect, insert }) {
        request?.abort();
        request = new AbortController();
        const current = ++generation;
        try {
            const response = await fetch(cp_url(`backlinks/search?q=${encodeURIComponent(query)}&limit=10`), { signal: request.signal });
            if (!response.ok) throw new Error('Could not search pages.');
            const items = await response.json();
            if (current !== generation) return;
            Object.assign(state, { items, query, command: async ({ title, isNew }) => {
                if (selecting) return;
                selecting = true;
                try {
                    if (isNew) await createEntry(title, source);
                    if (current === generation) {
                        close();
                        insert(title);
                    }
                } catch (error) {
                    Statamic.$toast.error(error.message);
                } finally {
                    selecting = false;
                }
            } });
            if (!app) {
                const element = document.createElement('div');
                app = createApp({ render: () => h(WikiLinkDropdown, { ...state, ref: value => { component = value; } }) });
                app.mount(element);
                popup = tippy(document.body, {
                    getReferenceClientRect: clientRect,
                    appendTo: () => document.body,
                    content: element,
                    showOnCreate: true,
                    interactive: true,
                    trigger: 'manual',
                    placement: 'bottom-start',
                    theme: 'wikilink',
                    maxWidth: 400,
                });
            } else {
                popup.setProps({ getReferenceClientRect: clientRect });
            }
        } catch (error) {
            if (error.name !== 'AbortError' && current === generation) {
                close();
                Statamic.$toast.error(error.message);
            }
        }
    }

    return { update, close, onKeyDown: event => component?.onKeyDown({ event }) ?? false };
}
