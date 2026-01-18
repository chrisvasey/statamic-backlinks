import { createApp, h } from 'vue';
import tippy from 'tippy.js';
import BacklinksSidebar from './components/BacklinksSidebar.vue';
import WikiLinkDropdown from './components/WikiLinkDropdown.vue';
import { createWikiLinkSuggestion } from './extensions/WikiLinkSuggestion.js';

Statamic.$components.register('backlinks-sidebar', BacklinksSidebar);

// Inject backlinks sidebar into entry publish forms
Statamic.$hooks.on('entry.loaded', (resolve, reject, { entry }) => {
    // The sidebar will be mounted by the component itself
    resolve();
});

// Register the wiki-link autocomplete extension for Bard
Statamic.$bard.addExtension(({ bard, tiptap }) => {
    let app = null;
    let popup = null;
    let dropdownElement = null;
    let componentRef = null;
    let currentItems = [];
    let currentQuery = '';
    let currentEditor = null;

    const fetchItems = async (query) => {
        try {
            const response = await fetch(
                `/cp/backlinks/search?q=${encodeURIComponent(query)}&limit=10`
            );
            if (!response.ok) return [];
            return await response.json();
        } catch (error) {
            console.error('Wiki-link search error:', error);
            return [];
        }
    };

    const updateDropdown = (items, query, command) => {
        currentItems = items;
        currentQuery = query;

        if (app) {
            app.unmount();
        }

        app = createApp({
            render() {
                return h(WikiLinkDropdown, {
                    ref: (el) => { componentRef = el; },
                    items: items,
                    query: query,
                    command: command,
                });
            },
        });

        app.mount(dropdownElement);
    };

    const createCommand = (editor) => {
        return ({ title }) => {
            editor.commands.insertWikiLink(title);
            destroyPopup();
        };
    };

    const destroyPopup = () => {
        if (popup && popup[0]) {
            popup[0].destroy();
            popup = null;
        }
        if (app) {
            app.unmount();
            app = null;
        }
        componentRef = null;
        currentItems = [];
        currentQuery = '';
    };

    // Create the extension using the TipTap modules from Statamic
    const WikiLinkSuggestion = createWikiLinkSuggestion(tiptap);

    return WikiLinkSuggestion.configure({
        onOpen: async ({ editor, query, clientRect }) => {
            currentEditor = editor;

            // Create container for the dropdown
            dropdownElement = document.createElement('div');
            dropdownElement.className = 'wikilink-dropdown-container';

            // Fetch initial items
            const items = await fetchItems(query);

            // Mount Vue component
            updateDropdown(items, query, createCommand(editor));

            // Create tippy popup
            popup = tippy('body', {
                getReferenceClientRect: clientRect,
                appendTo: () => document.body,
                content: dropdownElement,
                showOnCreate: true,
                interactive: true,
                trigger: 'manual',
                placement: 'bottom-start',
                theme: 'wikilink',
                maxWidth: 400,
            });
        },

        onUpdate: async ({ editor, query, clientRect }) => {
            currentEditor = editor;

            // Fetch filtered items
            const items = await fetchItems(query);

            // Update the dropdown
            updateDropdown(items, query, createCommand(editor));

            // Update popup position
            if (popup && popup[0]) {
                popup[0].setProps({
                    getReferenceClientRect: clientRect,
                });
            }
        },

        onClose: () => {
            destroyPopup();
        },

        onKeyDown: ({ event }) => {
            if (!componentRef || typeof componentRef.onKeyDown !== 'function') {
                return false;
            }

            return componentRef.onKeyDown({ event });
        },

        onLinkClick: async ({ title, event }) => {
            try {
                // Search for the entry by exact title
                const response = await fetch(
                    `/cp/backlinks/search?q=${encodeURIComponent(title)}&limit=10`
                );

                if (!response.ok) {
                    console.error('Failed to search for entry');
                    return;
                }

                const entries = await response.json();

                // Find exact match
                const exactMatch = entries.find(
                    e => e.title.toLowerCase() === title.toLowerCase()
                );

                if (exactMatch) {
                    // Open existing entry in new tab
                    window.open(exactMatch.edit_url, '_blank');
                } else {
                    // Create new entry
                    const csrfToken = Statamic.$config.get('csrfToken') ||
                                      document.querySelector('meta[name="csrf-token"]')?.content || '';

                    const createResponse = await fetch('/cp/backlinks/create', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({
                            title: title,
                            source_entry_id: bard?.entryId || null,
                        }),
                    });

                    if (!createResponse.ok) {
                        console.error('Failed to create entry');
                        return;
                    }

                    const result = await createResponse.json();
                    if (result.success && result.entry?.edit_url) {
                        window.open(result.entry.edit_url, '_blank');
                    }
                }
            } catch (error) {
                console.error('Wiki-link click error:', error);
            }
        },
    });
});
