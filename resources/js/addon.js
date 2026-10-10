import { publishContextKey } from '@statamic/cms/ui';
import BacklinksSidebar from './components/BacklinksSidebar.vue';
import { createWikiLinkSuggestion } from './extensions/WikiLinkSuggestion.js';
import { attachMarkdownWikiLinks } from './extensions/MarkdownWikiLinks.js';
import { createWikiLinkMenu, openWikiLink, sourceEntryId } from './WikiLinkMenu.js';

Statamic.booting(() => {
    Statamic.$components.register('backlinks-sidebar', BacklinksSidebar);

    Statamic.$events.$on('publish-container-created', ({ reference, pushComponent }) => {
        if (reference.value?.startsWith('entry::')) {
            pushComponent('backlinks-sidebar', { props: {} });
        }
    });

    Statamic.$hooks.on('entry.saved', (resolve, reject, { response }) => {
        Statamic.$events.$emit('backlinks-entry-saved', response.data.data.reference);
        resolve();
    });
});

const markdownEditors = new WeakMap();

Statamic.configuring(() => {
    Statamic.$app.mixin({
        inject: { backlinksPublishContext: { from: publishContextKey, default: null } },
        mounted() {
            if (this.config?.type !== 'markdown' || !('codemirror' in this)) return;
            let detach;
            const unwatch = this.$watch('codemirror', editor => {
                detach?.();
                if (editor) detach = attachMarkdownWikiLinks(editor, createWikiLinkMenu(() => sourceEntryId(this.backlinksPublishContext)));
            }, { immediate: true });
            markdownEditors.set(this, () => { unwatch(); detach?.(); });
        },
        beforeUnmount() {
            markdownEditors.get(this)?.();
            markdownEditors.delete(this);
        },
    });
});

Statamic.$bard.addExtension(({ bard, tiptap }) => {
    const source = () => sourceEntryId(bard.publishContainer);
    const menu = createWikiLinkMenu(source);
    const update = ({ editor, query, clientRect }) => menu.update({
        query, clientRect, insert: title => editor.commands.insertWikiLink(title),
    });
    return createWikiLinkSuggestion(tiptap).configure({
        onOpen: update,
        onUpdate: update,
        onClose: menu.close,
        onKeyDown: ({ event }) => menu.onKeyDown(event),
        onLinkClick: ({ title }) => openWikiLink(title, source),
    });
});
