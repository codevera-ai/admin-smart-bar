(function() {
    'use strict';

    let overlay, input, resultsContainer, clearBtn;
    let selectedIndex = -1;
    let searchTimeout = null;
    let currentResults = [];
    let menuItems = [];
    let fuse = null;
    let activeFilter = 'all';
    let collapsedSections = {};

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function init() {
        // Move WordPress notices on settings page
        moveSettingsNotices();

        // Also watch for notices being added dynamically
        if (document.querySelector('.asb-settings-header')) {
            observeNotices();
        }

        overlay = document.getElementById('asb-overlay');
        input = document.getElementById('asb-search-input');
        resultsContainer = document.getElementById('asb-results');
        clearBtn = document.getElementById('asb-clear-btn');

        if (!overlay || !input || !resultsContainer || !clearBtn) {
            return;
        }

        // Capture admin menu and initialize Fuse.js
        captureAdminMenu();
        initializeFuse();

        setupEventListeners();
    }

    function moveSettingsNotices() {
        const settingsHeader = document.querySelector('.asb-settings-header');

        if (!settingsHeader) {
            return;
        }

        const settingsWrap = settingsHeader.parentNode;

        // Find all notices inside the header (these need to be moved OUT)
        const noticesInHeader = settingsHeader.querySelectorAll('.notice, .updated, .error, .update-nag, .settings-error');

        if (noticesInHeader.length === 0) {
            return;
        }

        // Move each notice to after the settings header
        noticesInHeader.forEach(function(notice, index) {
            // Insert after the header
            if (settingsHeader.nextSibling) {
                settingsWrap.insertBefore(notice, settingsHeader.nextSibling);
            } else {
                settingsWrap.appendChild(notice);
            }
        });

        // Remove third-party plugin notices from this plugin's settings page
        removeThirdPartyNotices();
    }

    function removeThirdPartyNotices() {
        const settingsWrap = document.querySelector('.asb-settings-wrap');

        if (!settingsWrap) {
            return;
        }

        // Find and remove all third-party notices (notice notice-info from other plugins)
        const thirdPartyNotices = document.querySelectorAll('.notice.notice-info');

        thirdPartyNotices.forEach(function(notice) {
            // Only remove if it's not specifically from this plugin
            if (!notice.classList.contains('asb-notice')) {
                notice.remove();
            }
        });
    }

    function observeNotices() {
        const settingsWrap = document.querySelector('.asb-settings-wrap');

        if (!settingsWrap) {
            return;
        }

        // Watch for notices being added to the DOM
        const observer = new MutationObserver(function(mutations) {
            let shouldMove = false;
            mutations.forEach(function(mutation) {
                mutation.addedNodes.forEach(function(node) {
                    if (node.nodeType === 1 &&
                        (node.classList.contains('notice') ||
                         node.classList.contains('updated') ||
                         node.classList.contains('error') ||
                         node.classList.contains('update-nag'))) {
                        shouldMove = true;
                    }
                });
            });

            if (shouldMove) {
                moveSettingsNotices();
                removeThirdPartyNotices();
            }
        });

        observer.observe(settingsWrap, {
            childList: true,
            subtree: true
        });
    }

    function captureAdminMenu() {
        const adminMenu = document.getElementById('adminmenu');
        if (!adminMenu) {
            return;
        }

        menuItems = [];

        // Get all top-level menu items
        const menuTopItems = adminMenu.querySelectorAll('li.menu-top');

        menuTopItems.forEach(function(menuItem) {
            // Skip separators
            if (menuItem.classList.contains('wp-menu-separator')) {
                return;
            }

            // Get main menu link
            const menuLink = menuItem.querySelector('a.menu-top');
            if (!menuLink) {
                return;
            }

            const title = menuLink.querySelector('.wp-menu-name');
            if (!title || !title.textContent.trim()) {
                return;
            }

            // Clone the title element and remove notification badges
            const titleClone = title.cloneNode(true);
            const badges = titleClone.querySelectorAll('.update-plugins, .awaiting-mod, .update-count, .plugin-count, .count');
            badges.forEach(badge => badge.remove());

            const menuTitle = titleClone.textContent.trim();
            const menuUrl = menuLink.getAttribute('href');

            // Get icon
            let icon = 'dashicons-admin-generic';
            const iconDiv = menuLink.querySelector('.wp-menu-image');
            if (iconDiv) {
                const dashicon = iconDiv.querySelector('.dashicons');
                if (dashicon) {
                    const classes = Array.from(dashicon.classList);
                    const dashiconClass = classes.find(c => c.startsWith('dashicons-'));
                    if (dashiconClass) {
                        icon = dashiconClass;
                    }
                }
            }

            // Get submenu items
            const submenuArray = [];
            const submenu = menuItem.querySelector('.wp-submenu');
            if (submenu) {
                const submenuItems = submenu.querySelectorAll('li a');
                submenuItems.forEach(function(submenuLink) {
                    // Clone and remove notification badges from submenu titles
                    const submenuClone = submenuLink.cloneNode(true);
                    const submenuBadges = submenuClone.querySelectorAll('.update-plugins, .awaiting-mod, .update-count, .plugin-count, .count');
                    submenuBadges.forEach(badge => badge.remove());

                    const submenuTitle = submenuClone.textContent.trim();
                    const submenuUrl = submenuLink.getAttribute('href');

                    if (submenuTitle && submenuUrl && submenuUrl !== '#') {
                        submenuArray.push({
                            title: submenuTitle,
                            url: submenuUrl
                        });

                        // Also add as individual searchable item
                        menuItems.push({
                            title: submenuTitle,
                            url: submenuUrl,
                            icon: icon,
                            keywords: generateKeywords(submenuTitle),
                            parentTitle: menuTitle
                        });
                    }
                });
            }

            // Add main menu item with submenu items attached
            if (menuUrl && menuUrl !== '#') {
                menuItems.push({
                    title: menuTitle,
                    url: menuUrl,
                    icon: icon,
                    keywords: generateKeywords(menuTitle),
                    submenu: submenuArray
                });
            }
        });
    }

    function generateKeywords(title) {
        // Generate search keywords from menu title
        const keywords = [title.toLowerCase()];

        // Split by spaces and add variations
        const words = title.toLowerCase().split(/\s+/);

        // Add individual words
        words.forEach(word => {
            if (word.length > 2) {
                keywords.push(word);
            }
        });

        // Add word combinations
        if (words.length > 1) {
            // Reverse order
            keywords.push(words.reverse().join(' '));

            // Common synonyms
            const synonyms = {
                'add': ['new', 'create', 'make'],
                'new': ['add', 'create', 'make'],
                'create': ['add', 'new', 'make'],
                'edit': ['modify', 'change', 'update'],
                'view': ['see', 'show', 'list', 'all'],
                'all': ['view', 'list', 'show'],
                'settings': ['config', 'configuration', 'options', 'preferences'],
                'delete': ['remove', 'trash', 'erase'],
                'upload': ['add', 'import']
            };

            // Add synonyms
            words.forEach(word => {
                if (synonyms[word]) {
                    synonyms[word].forEach(synonym => {
                        keywords.push(synonym);
                        // Add synonym combinations
                        const otherWords = words.filter(w => w !== word);
                        if (otherWords.length > 0) {
                            keywords.push(synonym + ' ' + otherWords.join(' '));
                        }
                    });
                }
            });
        }

        return keywords;
    }

    function initializeFuse() {
        if (typeof Fuse === 'undefined') {
            return;
        }

        const options = {
            keys: [
                { name: 'title', weight: 2 },
                { name: 'keywords', weight: 1 }
            ],
            threshold: 0.3,
            distance: 100,
            minMatchCharLength: 2,
            includeScore: true,
            includeMatches: true,
            shouldSort: true,
            ignoreDiacritics: true,
            useExtendedSearch: false,
            ignoreLocation: true
        };

        fuse = new Fuse(menuItems, options);
    }

    function getSearchHistory() {
        try {
            const history = localStorage.getItem('asb_search_history');
            return history ? JSON.parse(history) : {};
        } catch (e) {
            return {};
        }
    }

    function saveSearchHistory(history) {
        try {
            localStorage.setItem('asb_search_history', JSON.stringify(history));
        } catch (e) {
            // Silently fail if localStorage is not available
        }
    }

    function addToHistory(item) {
        const history = getSearchHistory();
        const key = item.url;

        if (history[key]) {
            history[key].count++;
            history[key].lastClicked = Date.now();
        } else {
            history[key] = {
                title: item.title,
                type: item.type,
                icon: item.icon,
                url: item.url,
                count: 1,
                lastClicked: Date.now()
            };
        }

        // Keep only top 50 items
        const sorted = Object.entries(history)
            .sort((a, b) => b[1].count - a[1].count)
            .slice(0, 50);

        const trimmed = {};
        sorted.forEach(([key, value]) => {
            trimmed[key] = value;
        });

        saveSearchHistory(trimmed);
    }

    function getTopHistory(limit = 8) {
        const history = getSearchHistory();
        return Object.values(history)
            .sort((a, b) => b.count - a.count)
            .slice(0, limit);
    }

    function clearSearchHistory() {
        localStorage.removeItem('asb_search_history');
    }

    function detectInstalledPlugins() {
        const plugins = {
            woocommerce: false,
            yoast: false,
            elementor: false,
            acf: false,
            wpforms: false,
            contactForm7: false,
            jetpack: false,
            wprocket: false,
            updraftplus: false,
            gravityforms: false
        };

        menuItems.forEach(function(item) {
            const titleLower = item.title.toLowerCase();
            const urlLower = item.url.toLowerCase();

            if (titleLower.includes('woocommerce') || urlLower.includes('woocommerce')) {
                plugins.woocommerce = true;
            }
            if (titleLower.includes('yoast') || titleLower.includes('seo') && urlLower.includes('wpseo')) {
                plugins.yoast = true;
            }
            if (titleLower.includes('elementor')) {
                plugins.elementor = true;
            }
            if (titleLower.includes('acf') || titleLower.includes('custom fields') && urlLower.includes('acf')) {
                plugins.acf = true;
            }
            if (titleLower.includes('wpforms')) {
                plugins.wpforms = true;
            }
            if (titleLower.includes('contact') && titleLower.includes('form')) {
                plugins.contactForm7 = true;
            }
            if (titleLower.includes('jetpack')) {
                plugins.jetpack = true;
            }
            if (titleLower.includes('rocket') || titleLower.includes('cache') && urlLower.includes('wprocket')) {
                plugins.wprocket = true;
            }
            if (titleLower.includes('updraft')) {
                plugins.updraftplus = true;
            }
            if (titleLower.includes('gravity') && titleLower.includes('form')) {
                plugins.gravityforms = true;
            }
        });

        return plugins;
    }

    function generateExampleSearches() {
        const examples = [];
        const plugins = detectInstalledPlugins();

        // Core WordPress examples
        examples.push(
            { text: 'new post', description: 'Create a new post' },
            { text: 'all posts', description: 'View all posts' },
            { text: 'new page', description: 'Create a new page' },
            { text: 'media', description: 'View media library' },
            { text: 'users', description: 'Manage users' },
            { text: 'settings', description: 'Site settings' },
            { text: 'plugins', description: 'Manage plugins' },
            { text: 'themes', description: 'Change theme' }
        );

        // Plugin-specific examples
        if (plugins.woocommerce) {
            examples.push(
                { text: 'woo products', description: 'Manage WooCommerce products' },
                { text: 'woo orders', description: 'View orders' },
                { text: 'woo settings', description: 'WooCommerce settings' }
            );
        }

        if (plugins.yoast) {
            examples.push(
                { text: 'yoast', description: 'SEO settings' }
            );
        }

        if (plugins.elementor) {
            examples.push(
                { text: 'elementor', description: 'Page builder templates' }
            );
        }

        if (plugins.acf) {
            examples.push(
                { text: 'custom fields', description: 'Advanced Custom Fields' }
            );
        }

        if (plugins.wpforms || plugins.contactForm7 || plugins.gravityforms) {
            examples.push(
                { text: 'forms', description: 'Manage contact forms' }
            );
        }

        if (plugins.wprocket) {
            examples.push(
                { text: 'cache', description: 'Performance settings' }
            );
        }

        if (plugins.updraftplus) {
            examples.push(
                { text: 'backup', description: 'Manage backups' }
            );
        }

        // Shuffle and return limited examples
        return examples.sort(() => Math.random() - 0.5).slice(0, 8);
    }

    function renderEmptyState() {
        const history = getTopHistory(8);
        const hasHistory = history.length > 0;

        let html = '<div class="asb-empty-state">';

        if (hasHistory) {
            // Show history
            html += `
                <div class="asb-empty-state-header">
                    <h3>Recent & Popular</h3>
                    <button class="asb-clear-history" id="asb-clear-history">Clear</button>
                </div>
                <div class="asb-links-list">
            `;

            history.forEach(function(item, index) {
                if (index > 0) html += ', ';
                html += `<a href="#" class="asb-link-item" data-url="${escapeHtml(item.url)}">${escapeHtml(item.title)}</a>`;
            });

            html += '</div>';
        } else {
            // Show examples
            const examples = generateExampleSearches();

            html += `
                <div class="asb-empty-state-header">
                    <h3>Try searching for...</h3>
                </div>
                <div class="asb-links-list">
            `;

            examples.forEach(function(example, index) {
                if (index > 0) html += ', ';
                html += `<a href="#" class="asb-link-item" data-query="${escapeHtml(example.text)}">${escapeHtml(example.text)}</a>`;
            });

            html += '</div>';
        }

        // Always show tips
        html += `
            <div class="asb-tips">
                <p><strong>Tips:</strong></p>
                <ul>
                    <li>Search works with typos and abbreviations</li>
                    <li>Word order doesn't matter</li>
                    <li>Try synonyms like "create", "add", or "new"</li>
                </ul>
            </div>
        `;

        html += '</div>';

        resultsContainer.innerHTML = html;

        // Add click handlers for all links
        const linkItems = resultsContainer.querySelectorAll('.asb-link-item');
        linkItems.forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                if (hasHistory) {
                    // History item - navigate
                    const url = this.dataset.url;
                    navigateToResult(url);
                } else {
                    // Example item - trigger search
                    const query = this.dataset.query;
                    input.value = query;
                    performSearch(query);
                }
            });
        });

        // Add clear history handler if exists
        if (hasHistory) {
            const clearBtn = document.getElementById('asb-clear-history');
            if (clearBtn) {
                clearBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    clearSearchHistory();
                    renderEmptyState();
                });
            }
        }
    }

    function setupEventListeners() {
        // Admin bar click
        const trigger = document.querySelector('.admin-smart-bar-trigger');
        if (trigger) {
            trigger.addEventListener('click', function(e) {
                e.preventDefault();
                openOverlay();
            });
        }

        // Keyboard shortcut
        document.addEventListener('keydown', handleGlobalKeydown);

        // Overlay click to close
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) {
                closeOverlay();
            }
        });

        // Input events
        input.addEventListener('input', handleInput);
        input.addEventListener('keydown', handleInputKeydown);

        // Clear button click
        clearBtn.addEventListener('click', function(e) {
            e.preventDefault();
            input.value = '';
            clearBtn.style.display = 'none';
            renderEmptyState();
            currentResults = [];
            selectedIndex = -1;
            input.focus();
        });
    }

    function handleGlobalKeydown(e) {
        const shortcut = asbData.shortcut || 'ctrl+k';
        const parts = shortcut.split('+');
        const key = parts[parts.length - 1];
        const needsCtrl = parts.includes('ctrl');
        const needsCmd = parts.includes('cmd');

        // Check if shortcut matches
        const isMac = navigator.platform.toUpperCase().indexOf('MAC') >= 0;
        const modifierPressed = isMac ? e.metaKey : e.ctrlKey;

        let keyMatches = false;
        if (key === 'k') {
            keyMatches = e.key.toLowerCase() === 'k';
        } else if (key === 'space') {
            keyMatches = e.key === ' ' || e.code === 'Space';
        } else if (key === '/') {
            keyMatches = e.key === '/';
        }

        if (modifierPressed && keyMatches) {
            e.preventDefault();
            if (overlay.style.display === 'none' || !overlay.style.display) {
                openOverlay();
            } else {
                closeOverlay();
            }
        }

        // ESC to close
        if (e.key === 'Escape' && overlay.style.display !== 'none') {
            closeOverlay();
        }
    }

    function handleInput(e) {
        const query = e.target.value.trim();

        // Show/hide clear button
        if (e.target.value.length > 0) {
            clearBtn.style.display = 'flex';
        } else {
            clearBtn.style.display = 'none';
        }

        // Clear previous timeout
        if (searchTimeout) {
            clearTimeout(searchTimeout);
        }

        // Debounce search for performance
        searchTimeout = setTimeout(function() {
            if (query.length === 0) {
                renderEmptyState();
                currentResults = [];
                selectedIndex = -1;
                return;
            }

            if (query.length < 2) {
                return;
            }

            performSearch(query);
        }, 400); // 400ms debounce delay
    }

    function handleInputKeydown(e) {
        if (currentResults.length === 0) {
            return;
        }

        const visibleItems = getVisibleResultItems();

        switch(e.key) {
            case 'ArrowDown':
                e.preventDefault();
                if (visibleItems.length > 0) {
                    const currentVisibleIndex = visibleItems.findIndex(item =>
                        parseInt(item.dataset.index) === selectedIndex
                    );
                    const nextVisibleIndex = Math.min(currentVisibleIndex + 1, visibleItems.length - 1);
                    selectedIndex = parseInt(visibleItems[nextVisibleIndex].dataset.index);
                    updateSelection();
                }
                break;

            case 'ArrowUp':
                e.preventDefault();
                if (visibleItems.length > 0) {
                    const currentVisibleIndex = visibleItems.findIndex(item =>
                        parseInt(item.dataset.index) === selectedIndex
                    );
                    const prevVisibleIndex = Math.max(currentVisibleIndex - 1, 0);
                    selectedIndex = parseInt(visibleItems[prevVisibleIndex].dataset.index);
                    updateSelection();
                }
                break;

            case 'Enter':
                e.preventDefault();
                if (selectedIndex >= 0 && currentResults[selectedIndex]) {
                    const result = currentResults[selectedIndex];
                    // Add to history
                    addToHistory({
                        url: result.url,
                        title: result.title,
                        type: result.type,
                        icon: result.icon
                    });
                    navigateToResult(result.url);
                }
                break;
        }
    }

    function getVisibleResultItems() {
        // Get all result items
        const allItems = Array.from(resultsContainer.querySelectorAll('.asb-result-item'));

        // Filter to only visible items (not in collapsed or hidden sections)
        return allItems.filter(item => {
            const section = item.closest('.asb-result-section');
            const sectionContent = item.closest('.asb-section-content');

            // Check if section is hidden (filtered out)
            if (section && section.classList.contains('hidden')) {
                return false;
            }

            // Check if section content is collapsed
            if (sectionContent && sectionContent.classList.contains('collapsed')) {
                return false;
            }

            return true;
        });
    }

    function performSearch(query) {
        // Show loading state
        resultsContainer.innerHTML = '<div class="asb-loading"><div class="asb-loading-spinner"></div></div>';

        // Search locally using Fuse.js for menu items
        let localMenuResults = [];
        if (fuse) {
            const fuseResults = fuse.search(query);
            localMenuResults = fuseResults.map(result => ({
                title: result.item.title,
                type: 'Menu',
                url: result.item.url,
                icon: result.item.icon,
                submenu: result.item.submenu,
                parentTitle: result.item.parentTitle,
                score: result.score,
                source: 'local'
            }));
        }

        // Perform AJAX search for content (posts, pages, users, media)
        const formData = new FormData();
        formData.append('action', 'asb_search');
        formData.append('nonce', asbData.nonce);
        formData.append('query', query);

        fetch(asbData.ajaxUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(data => {
            let ajaxResults = [];
            if (data.success) {
                // Add source tag to AJAX results
                ajaxResults = data.data.map(item => ({
                    ...item,
                    source: 'ajax'
                }));
            }

            // Merge local menu results with AJAX results
            const allResults = [...localMenuResults, ...ajaxResults];

            // Deduplicate by URL (keep first occurrence)
            // Normalize URLs to handle different formats (full vs relative)
            const uniqueResults = [];
            const seenUrls = new Set();

            const normalizeUrl = function(url) {
                try {
                    // Use current page URL as base to properly resolve relative URLs
                    const urlObj = new URL(url, window.location.href);
                    return urlObj.pathname + urlObj.search;
                } catch (e) {
                    return url;
                }
            };

            allResults.forEach(result => {
                const normalizedUrl = normalizeUrl(result.url);
                if (!seenUrls.has(normalizedUrl)) {
                    uniqueResults.push(result);
                    seenUrls.add(normalizedUrl);
                }
            });

            // Sort by relevance (Fuse.js score is lower = better, so prioritise local results)
            uniqueResults.sort((a, b) => {
                // Local menu results come first (sorted by score)
                if (a.source === 'local' && b.source === 'local') {
                    return a.score - b.score;
                }
                if (a.source === 'local') return -1;
                if (b.source === 'local') return 1;
                return 0;
            });

            currentResults = uniqueResults;
            selectedIndex = currentResults.length > 0 ? 0 : -1;
            renderResults(currentResults);
        })
        .catch(error => {
            // If AJAX fails, still show local results
            if (localMenuResults.length > 0) {
                currentResults = localMenuResults;
                selectedIndex = 0;
                renderResults(currentResults);
            } else {
                showError('Search failed');
            }
        });
    }

    function renderResults(results) {
        if (results.length === 0) {
            resultsContainer.innerHTML = `
                <div class="asb-empty">
                    <div class="asb-empty-icon dashicons dashicons-search"></div>
                    <p class="asb-empty-text">No results found</p>
                </div>
            `;
            return;
        }

        // Group results by type
        const groupedResults = {
            'Menu': results.filter(r => r.type === 'Menu'),
            'Post': results.filter(r => r.type === 'Post'),
            'Page': results.filter(r => r.type === 'Page'),
            'Media': results.filter(r => r.type === 'Media'),
            'Product': results.filter(r => r.type === 'Product'),
            'User': results.filter(r => r.type === 'User')
        };

        // Calculate counts for filters
        const counts = {
            all: results.length,
            actions: groupedResults['Menu'].length,
            posts: groupedResults['Post'].length,
            pages: groupedResults['Page'].length,
            media: groupedResults['Media'].length,
            products: groupedResults['Product'].length,
            users: groupedResults['User'].length
        };

        // Build filter tabs
        let filterHtml = '<div class="asb-filter-tabs">';

        const filters = [
            { key: 'all', label: 'All', count: counts.all },
            { key: 'actions', label: 'Actions', count: counts.actions },
            { key: 'posts', label: 'Posts', count: counts.posts },
            { key: 'pages', label: 'Pages', count: counts.pages },
            { key: 'media', label: 'Media', count: counts.media },
            { key: 'products', label: 'Products', count: counts.products },
            { key: 'users', label: 'Users', count: counts.users }
        ];

        filters.forEach(function(filter) {
            if (filter.count > 0 || filter.key === 'all') {
                const isActive = activeFilter === filter.key ? 'active' : '';
                const isDisabled = filter.count === 0 ? 'disabled' : '';
                filterHtml += `
                    <button class="asb-filter-tab ${isActive} ${isDisabled}"
                            data-filter="${filter.key}"
                            ${filter.count === 0 ? 'disabled' : ''}>
                        ${filter.label} <span class="asb-filter-count">(${filter.count})</span>
                    </button>
                `;
            }
        });

        filterHtml += '</div>';

        // Build sections
        let sectionsHtml = '<div class="asb-results-sections">';
        let index = 0;

        // Section configurations
        const sectionConfigs = [
            { key: 'Menu', label: 'Actions', filterKey: 'actions', icon: 'dashicons-admin-generic' },
            { key: 'Post', label: 'Posts', filterKey: 'posts', icon: 'dashicons-admin-post' },
            { key: 'Page', label: 'Pages', filterKey: 'pages', icon: 'dashicons-admin-page' },
            { key: 'Media', label: 'Media', filterKey: 'media', icon: 'dashicons-admin-media' },
            { key: 'Product', label: 'Products', filterKey: 'products', icon: 'dashicons-products' },
            { key: 'User', label: 'Users', filterKey: 'users', icon: 'dashicons-admin-users' }
        ];

        sectionConfigs.forEach(function(section) {
            const sectionResults = groupedResults[section.key];
            if (sectionResults.length === 0) return;

            // Check if this section should be visible based on active filter
            const isVisible = activeFilter === 'all' || activeFilter === section.filterKey;
            const visibilityClass = isVisible ? '' : 'hidden';

            // Check if section is collapsed
            const isCollapsed = collapsedSections[section.key] || false;
            const collapsedClass = isCollapsed ? 'collapsed' : '';

            sectionsHtml += `
                <div class="asb-result-section ${visibilityClass}" data-section="${section.key}">
                    <div class="asb-section-header-collapsible ${collapsedClass}" data-section="${section.key}">
                        <span class="asb-section-toggle dashicons ${isCollapsed ? 'dashicons-arrow-right' : 'dashicons-arrow-down'}"></span>
                        <span class="asb-section-title">${section.label}</span>
                        <span class="asb-section-count">(${sectionResults.length})</span>
                    </div>
                    <div class="asb-section-content ${collapsedClass}">
            `;

            // Render items for this section
            sectionResults.forEach(function(result) {
                const typeClass = result.type ? 'type-' + result.type.toLowerCase() : '';
                const isActive = index === selectedIndex ? 'active' : '';

                if (section.key === 'Menu') {
                    // Render menu items (actions)
                    let submenuHtml = '';
                    if (result.submenu && result.submenu.length > 0) {
                        const submenuLinks = result.submenu.map(function(item) {
                            return `<a href="${item.url}" class="asb-submenu-link">${escapeHtml(item.title)}</a>`;
                        }).join('');
                        submenuHtml = `<div class="asb-submenu-links">${submenuLinks}</div>`;
                    }

                    let titleHtml = '';
                    if (result.parentTitle) {
                        titleHtml = `<span class="asb-parent-title">${escapeHtml(result.parentTitle)}</span> <span class="asb-title-separator">→</span> ${escapeHtml(result.title)}`;
                    } else {
                        titleHtml = escapeHtml(result.title);
                    }

                    sectionsHtml += `
                        <div class="asb-result-item ${isActive}" data-index="${index}" data-url="${result.url}" data-title="${escapeHtml(result.title)}" data-type="${result.type}" data-icon="${result.icon || 'dashicons-admin-generic'}">
                            <div class="asb-result-icon">
                                <span class="dashicons ${result.icon || 'dashicons-admin-generic'}"></span>
                            </div>
                            <div class="asb-result-content">
                                <div class="asb-result-title">${titleHtml}</div>
                                ${submenuHtml}
                            </div>
                        </div>
                    `;
                } else {
                    // Render content items (posts, pages, media, products, users)
                    sectionsHtml += `
                        <div class="asb-result-item ${isActive}" data-index="${index}" data-url="${result.url}" data-title="${escapeHtml(result.title)}" data-type="${result.type}" data-icon="${result.icon || section.icon}">
                            <div class="asb-result-icon">
                                <span class="dashicons ${result.icon || section.icon}"></span>
                            </div>
                            <div class="asb-result-content">
                                <div class="asb-result-header">
                                    <div class="asb-result-title">${escapeHtml(result.title)}</div>
                                    <div class="asb-result-actions">
                                        <a href="${result.url}" class="asb-action-link" data-action="edit">Edit</a>
                                        ${result.view_url ? `<a href="${result.view_url}" class="asb-action-link" data-action="view" target="_blank">View</a>` : ''}
                                    </div>
                                </div>
                                <div class="asb-result-meta">
                                    <span class="asb-result-type ${typeClass}">${result.type || 'Item'}</span>
                                    ${result.status ? `<span> · ${result.status}</span>` : ''}
                                    ${result.id ? `<span> · ID: ${escapeHtml(result.id)}</span>` : ''}
                                </div>
                            </div>
                        </div>
                    `;
                }
                index++;
            });

            sectionsHtml += `
                    </div>
                </div>
            `;
        });

        sectionsHtml += '</div>';

        // Combine filter tabs and sections
        resultsContainer.innerHTML = filterHtml + sectionsHtml;

        // Add click handlers for filter tabs
        const filterTabs = resultsContainer.querySelectorAll('.asb-filter-tab');
        filterTabs.forEach(function(tab) {
            tab.addEventListener('click', function() {
                if (this.disabled) return;

                activeFilter = this.dataset.filter;

                // Update active tab
                filterTabs.forEach(t => t.classList.remove('active'));
                this.classList.add('active');

                // Show/hide sections based on filter
                const sections = resultsContainer.querySelectorAll('.asb-result-section');
                sections.forEach(function(section) {
                    const sectionType = section.dataset.section;
                    const filterKey = activeFilter;

                    if (filterKey === 'all') {
                        section.classList.remove('hidden');
                    } else {
                        // Map section types to filter keys
                        const typeToFilter = {
                            'Menu': 'actions',
                            'Post': 'posts',
                            'Page': 'pages',
                            'Media': 'media',
                            'Product': 'products',
                            'User': 'users'
                        };

                        if (typeToFilter[sectionType] === filterKey) {
                            section.classList.remove('hidden');
                        } else {
                            section.classList.add('hidden');
                        }
                    }
                });

                // Reset selection to first visible item
                selectedIndex = 0;
                updateSelection();
            });
        });

        // Add click handlers for collapsible section headers
        const sectionHeaders = resultsContainer.querySelectorAll('.asb-section-header-collapsible');
        sectionHeaders.forEach(function(header) {
            header.addEventListener('click', function() {
                const sectionKey = this.dataset.section;
                const isCollapsed = this.classList.contains('collapsed');
                const sectionContent = this.nextElementSibling;
                const toggleIcon = this.querySelector('.asb-section-toggle');

                // Toggle collapsed state
                if (isCollapsed) {
                    this.classList.remove('collapsed');
                    sectionContent.classList.remove('collapsed');
                    toggleIcon.classList.remove('dashicons-arrow-right');
                    toggleIcon.classList.add('dashicons-arrow-down');
                    collapsedSections[sectionKey] = false;
                } else {
                    this.classList.add('collapsed');
                    sectionContent.classList.add('collapsed');
                    toggleIcon.classList.remove('dashicons-arrow-down');
                    toggleIcon.classList.add('dashicons-arrow-right');
                    collapsedSections[sectionKey] = true;
                }
            });
        });

        // Add click handlers for section content
        const sectionContents = resultsContainer.querySelectorAll('.asb-section-content');
        sectionContents.forEach(function(content) {
            content.addEventListener('click', function(e) {
                // Find the clicked result item
                const resultItem = e.target.closest('.asb-result-item');
                if (!resultItem) return;

                // Don't navigate if clicking on action links or submenu links
                if (e.target.classList.contains('asb-action-link') || e.target.classList.contains('asb-submenu-link')) {
                    return;
                }

                e.preventDefault();
                const url = resultItem.dataset.url;
                if (url) {
                    addToHistory({
                        url: url,
                        title: resultItem.dataset.title,
                        type: resultItem.dataset.type,
                        icon: resultItem.dataset.icon
                    });
                    navigateToResult(url);
                }
            });
        });

        // Add click handlers for result items
        const resultItems = resultsContainer.querySelectorAll('.asb-result-item');
        resultItems.forEach(function(item) {
            item.addEventListener('mouseenter', function() {
                selectedIndex = parseInt(this.dataset.index);
                updateSelection();
            });
        });

        // Add click handlers for action links
        const actionLinks = resultsContainer.querySelectorAll('.asb-action-link');
        actionLinks.forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.stopPropagation();
                // Let the link work naturally
            });
        });
    }

    function updateSelection() {
        const items = resultsContainer.querySelectorAll('.asb-result-item');
        items.forEach(function(item, index) {
            if (index === selectedIndex) {
                item.classList.add('active');
                item.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            } else {
                item.classList.remove('active');
            }
        });
    }

    function navigateToResult(url) {
        window.location.href = url;
    }

    function showError(message) {
        resultsContainer.innerHTML = `
            <div class="asb-empty">
                <div class="asb-empty-icon dashicons dashicons-warning"></div>
                <p class="asb-empty-text">${escapeHtml(message)}</p>
            </div>
        `;
    }

    function openOverlay() {
        overlay.style.display = 'flex';
        input.value = '';
        clearBtn.style.display = 'none';
        input.focus();
        renderEmptyState();
        currentResults = [];
        selectedIndex = -1;
        activeFilter = 'all';
        collapsedSections = {};
        document.body.style.overflow = 'hidden';
    }

    function closeOverlay() {
        overlay.style.display = 'none';
        document.body.style.overflow = '';
        input.value = '';
        resultsContainer.innerHTML = '';
        currentResults = [];
        selectedIndex = -1;
        activeFilter = 'all';
        collapsedSections = {};
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

})();
