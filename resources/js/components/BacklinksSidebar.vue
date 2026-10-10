<script setup>
import { computed, ref, watch, onMounted, onBeforeUnmount } from 'vue';
import { Card, injectPublishContext } from '@statamic/cms/ui';

const { reference } = injectPublishContext();
const entryId = computed(() => reference.value?.startsWith('entry::') ? reference.value.slice(7) : null);
const anchor = ref(null);
const sidebar = ref(null);
let observer;

const loading = ref(true);
const backlinks = ref([]);
const count = ref(0);
const error = ref(null);

watch(entryId, async (id, previousId, onCleanup) => {
    const controller = new AbortController();
    onCleanup(() => controller.abort());

    backlinks.value = [];
    count.value = 0;
    error.value = null;
    loading.value = Boolean(id);

    if (!id) return;

    try {
        const response = await fetch(cp_url(`backlinks/${encodeURIComponent(id)}`), {
            signal: controller.signal,
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            throw new Error('Failed to fetch backlinks');
        }

        const data = await response.json();
        if (controller.signal.aborted) return;
        backlinks.value = data.backlinks;
        count.value = data.count;
    } catch (err) {
        if (controller.signal.aborted) return;
        error.value = err.message;
        console.error('Backlinks: Error fetching backlinks', err);
    } finally {
        if (!controller.signal.aborted) loading.value = false;
    }
}, { immediate: true });

onMounted(() => {
    const form = anchor.value.parentElement;
    const updateSidebar = () => { sidebar.value = form.querySelector('aside'); };

    // shortcut: Statamic has no sidebar extension slot; revisit this selector if its publish layout changes.
    observer = new MutationObserver(updateSidebar);
    observer.observe(form, { childList: true, subtree: true });
    updateSidebar();
});

onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <div ref="anchor" class="contents">
        <Teleport :to="sidebar || anchor" :disabled="!sidebar">
            <Card v-if="entryId" class="p-0 mb-4">
                <div class="flex justify-between items-center p-3 border-b dark:border-dark-900">
                    <h2 class="text-sm font-bold">Backlinks</h2>
                    <span class="text-xs text-gray-500 dark:text-dark-300" v-if="!loading">
                        {{ count }}
                    </span>
                </div>

                <div v-if="loading" class="p-3 text-sm text-gray-500 dark:text-dark-300">
                    Loading...
                </div>

                <div v-else-if="error" class="p-3 text-sm text-red-500">
                    {{ error }}
                </div>

                <div v-else-if="count === 0" class="p-3 text-sm text-gray-500 dark:text-dark-300">
                    No pages link to this entry.
                </div>

                <div v-else class="divide-y dark:divide-dark-900">
                    <a
                        v-for="backlink in backlinks"
                        :key="backlink.id"
                        :href="backlink.edit_url"
                        class="block p-3 hover:bg-gray-50 dark:hover:bg-dark-700 text-sm"
                    >
                        <div class="font-medium text-gray-900 dark:text-gray-100">
                            {{ backlink.title }}
                        </div>
                        <div class="text-xs text-gray-500 dark:text-dark-300">
                            {{ backlink.collection }}
                        </div>
                    </a>
                </div>
            </Card>
        </Teleport>
    </div>
</template>
