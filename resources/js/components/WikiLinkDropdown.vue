<template>
    <div class="wikilink-dropdown" v-if="items.length || query">
        <button
            v-for="(item, index) in items"
            :key="item.id"
            class="wikilink-dropdown-item"
            :class="{ 'is-selected': index === selectedIndex }"
            @click="selectItem(index)"
            @mouseenter="selectedIndex = index"
        >
            <span class="wikilink-dropdown-title">{{ item.title }}</span>
            <span class="wikilink-dropdown-collection">{{ item.collection_title }}</span>
        </button>
        <button
            v-if="showCreateOption"
            class="wikilink-dropdown-item wikilink-dropdown-create"
            :class="{ 'is-selected': selectedIndex === items.length }"
            @click="createNew"
            @mouseenter="selectedIndex = items.length"
        >
            <span class="wikilink-dropdown-title">Create "{{ query }}"</span>
            <span class="wikilink-dropdown-collection">New page</span>
        </button>
        <div v-if="!items.length && !query" class="wikilink-dropdown-empty">
            Type to search pages...
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';

const props = defineProps({
    items: {
        type: Array,
        required: true,
    },
    query: {
        type: String,
        default: '',
    },
    command: {
        type: Function,
        required: true,
    },
});

const selectedIndex = ref(0);

const showCreateOption = computed(() => {
    // Show create option if there's a query and no exact match
    if (!props.query) return false;
    const normalizedQuery = props.query.toLowerCase().trim();
    const hasExactMatch = props.items.some(
        item => item.title.toLowerCase().trim() === normalizedQuery
    );
    return !hasExactMatch;
});

const totalItems = computed(() => {
    return props.items.length + (showCreateOption.value ? 1 : 0);
});

watch(() => props.items, () => {
    selectedIndex.value = 0;
});

function upHandler() {
    selectedIndex.value = (selectedIndex.value + totalItems.value - 1) % totalItems.value;
}

function downHandler() {
    selectedIndex.value = (selectedIndex.value + 1) % totalItems.value;
}

function enterHandler() {
    if (selectedIndex.value < props.items.length) {
        selectItem(selectedIndex.value);
    } else if (showCreateOption.value) {
        createNew();
    }
}

function selectItem(index) {
    const item = props.items[index];
    if (item) {
        props.command({ title: item.title, id: item.id });
    }
}

function createNew() {
    // Insert the wiki-link with the typed query
    // The actual page creation happens when rendering
    props.command({ title: props.query, id: null, isNew: true });
}

function onKeyDown({ event }) {
    if (event.key === 'ArrowUp') {
        upHandler();
        return true;
    }

    if (event.key === 'ArrowDown') {
        downHandler();
        return true;
    }

    if (event.key === 'Enter') {
        enterHandler();
        return true;
    }

    return false;
}

// Expose the onKeyDown method for external access
defineExpose({
    onKeyDown,
});
</script>
