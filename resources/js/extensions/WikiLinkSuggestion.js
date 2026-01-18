// Factory function that creates the extension using provided TipTap modules
export function createWikiLinkSuggestion(tiptap) {
    const { Extension } = tiptap.core;
    const { Plugin, PluginKey } = tiptap.pm.state;
    const { Decoration, DecorationSet } = tiptap.pm.view;

    const WikiLinkPluginKey = new PluginKey('wikiLinkSuggestion');
    const WikiLinkDecorationKey = new PluginKey('wikiLinkDecoration');

    // Shared state outside ProseMirror to avoid transaction conflicts
    let suggestionState = {
        active: false,
        query: '',
        triggerPos: null,
        view: null,
    };

    return Extension.create({
        name: 'wikiLinkSuggestion',

        addOptions() {
            return {
                onOpen: () => {},
                onClose: () => {},
                onUpdate: () => {},
                onKeyDown: () => false,
                onLinkClick: () => {}, // Called when a wiki-link is clicked
            };
        },

        addProseMirrorPlugins() {
            const extension = this;

            const checkForTrigger = (view) => {
                const { state } = view;
                const { from } = state.selection;

                if (from < 2) {
                    return false;
                }

                try {
                    const textBefore = state.doc.textBetween(from - 2, from, '\n', '\0');
                    return textBefore === '[[';
                } catch (e) {
                    return false;
                }
            };

            const getClientRect = (view) => {
                const { from } = view.state.selection;
                const coords = view.coordsAtPos(from);
                return {
                    top: coords.top,
                    bottom: coords.bottom,
                    left: coords.left,
                    right: coords.left,
                    width: 0,
                    height: coords.bottom - coords.top,
                };
            };

            const updateSuggestionState = (view) => {
                if (!suggestionState.active) return;

                const { state } = view;
                const { from } = state.selection;

                if (from < suggestionState.triggerPos + 2) {
                    closeSuggestion();
                    return;
                }

                try {
                    const textFromTrigger = state.doc.textBetween(
                        suggestionState.triggerPos,
                        from,
                        '\n',
                        '\0'
                    );

                    if (!textFromTrigger.startsWith('[[') || textFromTrigger.includes(']]')) {
                        closeSuggestion();
                        return;
                    }

                    const query = textFromTrigger.slice(2);
                    if (query !== suggestionState.query) {
                        suggestionState.query = query;
                        extension.options.onUpdate({
                            editor: extension.editor,
                            query,
                            range: { from: suggestionState.triggerPos, to: from },
                            clientRect: () => getClientRect(view),
                        });
                    }
                } catch (e) {
                    closeSuggestion();
                }
            };

            const openSuggestion = (view, triggerPos) => {
                suggestionState = {
                    active: true,
                    query: '',
                    triggerPos,
                    view,
                };

                extension.options.onOpen({
                    editor: extension.editor,
                    query: '',
                    range: { from: triggerPos, to: view.state.selection.from },
                    clientRect: () => getClientRect(view),
                });
            };

            const closeSuggestion = () => {
                if (suggestionState.active) {
                    suggestionState = {
                        active: false,
                        query: '',
                        triggerPos: null,
                        view: null,
                    };
                    extension.options.onClose();
                }
            };

            // Find all [[...]] patterns in the document
            const findWikiLinks = (doc) => {
                const decorations = [];
                const regex = /\[\[([^\]]+)\]\]/g;

                doc.descendants((node, pos) => {
                    if (!node.isText) return;

                    const text = node.text;
                    let match;

                    while ((match = regex.exec(text)) !== null) {
                        const start = pos + match.index;
                        const end = start + match[0].length;
                        const title = match[1];

                        decorations.push(
                            Decoration.inline(start, end, {
                                class: 'wikilink-inline',
                                'data-wikilink-title': title,
                            })
                        );
                    }
                });

                return DecorationSet.create(doc, decorations);
            };

            return [
                // Suggestion plugin
                new Plugin({
                    key: WikiLinkPluginKey,

                    props: {
                        handleKeyDown(view, event) {
                            if (suggestionState.active) {
                                if (event.key === 'Escape') {
                                    closeSuggestion();
                                    return true;
                                }

                                if (['ArrowUp', 'ArrowDown', 'Enter'].includes(event.key)) {
                                    return extension.options.onKeyDown({ event });
                                }
                            }

                            return false;
                        },
                    },

                    view() {
                        return {
                            update: (view, prevState) => {
                                if (prevState.doc.eq(view.state.doc) &&
                                    prevState.selection.eq(view.state.selection)) {
                                    return;
                                }

                                if (suggestionState.active) {
                                    updateSuggestionState(view);
                                    return;
                                }

                                if (checkForTrigger(view)) {
                                    const { from } = view.state.selection;
                                    openSuggestion(view, from - 2);
                                }
                            },

                            destroy: () => {
                                closeSuggestion();
                            },
                        };
                    },
                }),

                // Decoration plugin for styling wiki-links
                new Plugin({
                    key: WikiLinkDecorationKey,

                    state: {
                        init(_, { doc }) {
                            return findWikiLinks(doc);
                        },

                        apply(tr, oldDecorations) {
                            if (tr.docChanged) {
                                return findWikiLinks(tr.doc);
                            }
                            return oldDecorations;
                        },
                    },

                    props: {
                        decorations(state) {
                            return this.getState(state);
                        },

                        handleClick(view, pos, event) {
                            // Check if clicked on a wiki-link decoration
                            const target = event.target;
                            if (target.classList && target.classList.contains('wikilink-inline')) {
                                const title = target.getAttribute('data-wikilink-title');
                                if (title) {
                                    event.preventDefault();
                                    extension.options.onLinkClick({ title, event });
                                    return true;
                                }
                            }
                            return false;
                        },
                    },
                }),
            ];
        },

        addCommands() {
            return {
                insertWikiLink:
                    (title) =>
                    ({ state, dispatch }) => {
                        if (!suggestionState.active || suggestionState.triggerPos === null) {
                            return false;
                        }

                        const { from } = state.selection;
                        const triggerPos = suggestionState.triggerPos;

                        const tr = state.tr
                            .delete(triggerPos, from)
                            .insertText(`[[${title}]]`, triggerPos);

                        if (dispatch) {
                            dispatch(tr);
                        }

                        suggestionState = {
                            active: false,
                            query: '',
                            triggerPos: null,
                            view: null,
                        };

                        return true;
                    },
            };
        },
    });
}
