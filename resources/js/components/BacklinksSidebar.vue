<script setup>
import { ref, onMounted } from 'vue';
import { Card } from '@statamic/cms/ui';

const props = defineProps({
    entryId: {
        type: String,
        required: true,
    },
});

const loading = ref(true);
const backlinks = ref([]);
const count = ref(0);
const error = ref(null);

async function fetchBacklinks() {
    try {
        loading.value = true;
        error.value = null;

        const response = await fetch(`/cp/backlinks/${props.entryId}`, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            throw new Error('Failed to fetch backlinks');
        }

        const data = await response.json();
        backlinks.value = data.backlinks;
        count.value = data.count;
    } catch (err) {
        error.value = err.message;
        console.error('Backlinks: Error fetching backlinks', err);
    } finally {
        loading.value = false;
    }
}

onMounted(() => {
    fetchBacklinks();
});
</script>

<template>
    <Card class="p-0 mt-4">
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
</template>
