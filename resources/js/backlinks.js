// Statamic Backlinks - Entry Sidebar Integration
(function () {
    // Wait for Statamic to be ready
    if (typeof Statamic === 'undefined') {
        console.warn('Backlinks: Statamic not found');
        return;
    }

    // Get the entry ID from the current URL
    function getEntryIdFromUrl() {
        const match = window.location.pathname.match(/\/cp\/collections\/[^/]+\/entries\/([^/]+)/);
        return match ? match[1] : null;
    }

    // Fetch backlinks for an entry
    async function fetchBacklinks(entryId) {
        try {
            const response = await fetch(`/cp/backlinks/${entryId}`, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            if (!response.ok) throw new Error('Failed to fetch backlinks');
            return await response.json();
        } catch (error) {
            console.error('Backlinks: Error fetching backlinks', error);
            return null;
        }
    }

    // Create backlinks panel HTML
    function createBacklinksPanel(data) {
        const panel = document.createElement('div');
        panel.className = 'card p-0 mt-4';
        panel.id = 'backlinks-panel';

        let content = `
            <div class="flex justify-between items-center p-3 border-b dark:border-dark-900">
                <h2 class="text-sm font-bold">Backlinks</h2>
                <span class="text-xs text-gray-500 dark:text-dark-300">${data.count}</span>
            </div>
        `;

        if (data.count === 0) {
            content += `
                <div class="p-3 text-sm text-gray-500 dark:text-dark-300">
                    No pages link to this entry.
                </div>
            `;
        } else {
            content += '<div class="divide-y dark:divide-dark-900">';
            data.backlinks.forEach(backlink => {
                content += `
                    <a href="${backlink.edit_url}" class="block p-3 hover:bg-gray-50 dark:hover:bg-dark-700 text-sm">
                        <div class="font-medium text-gray-900 dark:text-gray-100">${escapeHtml(backlink.title)}</div>
                        <div class="text-xs text-gray-500 dark:text-dark-300">${backlink.collection}</div>
                    </a>
                `;
            });
            content += '</div>';
        }

        panel.innerHTML = content;
        return panel;
    }

    // Escape HTML to prevent XSS
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Inject the backlinks panel into the sidebar
    function injectBacklinksPanel() {
        const entryId = getEntryIdFromUrl();
        if (!entryId) return;

        // Remove existing panel if present
        const existing = document.getElementById('backlinks-panel');
        if (existing) existing.remove();

        // Find the sidebar
        const sidebar = document.querySelector('.publish-sidebar');
        if (!sidebar) {
            // Try again later if sidebar not found yet
            setTimeout(injectBacklinksPanel, 500);
            return;
        }

        // Fetch and display backlinks
        fetchBacklinks(entryId).then(data => {
            if (data) {
                const panel = createBacklinksPanel(data);
                sidebar.appendChild(panel);
            }
        });
    }

    // Handle missing wiki-link creation via double-click
    function handleMissingLinkCreation() {
        document.addEventListener('dblclick', async (e) => {
            const target = e.target.closest('.wiki-link-missing');
            if (!target) return;

            const title = target.dataset.title;
            if (!title) return;

            if (!confirm(`Create new page "${title}"?`)) return;

            const entryId = getEntryIdFromUrl();

            try {
                const response = await fetch('/cp/backlinks/create', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    body: JSON.stringify({
                        title: title,
                        source_entry_id: entryId,
                    }),
                });

                if (!response.ok) throw new Error('Failed to create page');

                const data = await response.json();
                if (data.success && data.entry.edit_url) {
                    window.location.href = data.entry.edit_url;
                }
            } catch (error) {
                console.error('Backlinks: Error creating page', error);
                alert('Failed to create page. Please try again.');
            }
        });
    }

    // Initialize when the page is ready
    function init() {
        // Inject panel on page load
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', injectBacklinksPanel);
        } else {
            injectBacklinksPanel();
        }

        // Handle SPA navigation (for Vue router)
        if (typeof MutationObserver !== 'undefined') {
            const observer = new MutationObserver((mutations) => {
                for (const mutation of mutations) {
                    if (mutation.type === 'childList') {
                        const sidebar = document.querySelector('.publish-sidebar');
                        const panel = document.getElementById('backlinks-panel');
                        if (sidebar && !panel) {
                            injectBacklinksPanel();
                        }
                    }
                }
            });

            observer.observe(document.body, { childList: true, subtree: true });
        }

        // Set up missing link creation handler
        handleMissingLinkCreation();
    }

    init();
})();
