=== Admin Smart Bar ===
Contributors: codevera
Tags: admin, search, command palette, keyboard shortcuts, productivity
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.2
Stable tag: 1.0.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightning-fast command palette for WordPress admin powered by advanced full-text search. Navigate your WordPress site instantly with keyboard shortcuts.

== Description ==

Admin Smart Bar brings Mac Spotlight-style search to your WordPress admin. Powered by advanced full-text search technology with fuzzy matching and intelligent ranking, it delivers instant, accurate results as you type.

Instead of clicking through menus, just press `Ctrl+K` (or `Cmd+K` on Mac), type what you're looking for, and go straight there.

= Features =

**Advanced full-text search**
Powered by SQLite FTS5 with BM25 ranking for lightning-fast, relevant results:
* Posts and pages (including drafts and private content)
* Page builder content (Elementor, Divi, Beaver Builder, and 7 more)
* Media files (images, videos, documents)
* Users (with capability-based filtering)
* WooCommerce products (if WooCommerce is installed)
* Fuzzy matching for typo correction
* Prefix search for instant type-ahead results
* Intelligent content weighting (titles ranked higher than content)

**Smart admin navigation**
Find WordPress admin pages instantly:
* Dashboard, posts, pages, media
* Comments, appearance, plugins, users
* Settings, tools, and more
* All submenu items and custom post types

**Popular plugin shortcuts**
Quickly access admin pages for installed plugins:
* WooCommerce (products, orders, settings, analytics)
* Yoast SEO (settings, search appearance, tools)
* Elementor (templates, settings)
* Advanced Custom Fields (field groups)
* Contact Form 7, WPForms, Jetpack, and more

**Keyboard-first design**
* `Ctrl+K` or `Cmd+K` - Open search
* `↑` `↓` - Navigate results
* `Enter` - Open selected item
* `Esc` - Close search

**Quick actions**
Every search result shows quick actions:
* Edit - Jump to edit screen
* View - Open frontend view (published posts) or preview (drafts)

**Page builder support**
Admin Smart Bar automatically indexes content from these popular page builders:
* Elementor
* Divi Builder
* Beaver Builder
* Bricks Builder
* Oxygen Builder
* Breakdance
* WPBakery Page Builder
* Visual Composer
* Thrive Architect
* SeedProd

Content created with these page builders is fully searchable, with all markup and shortcodes automatically stripped. No configuration needed.

= Privacy =

Admin Smart Bar:
* Does not collect any data
* Does not make external requests
* All searches happen on your server
* No tracking or analytics

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/admin-smart-bar` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Press `Ctrl+K` (or `Cmd+K` on Mac) to start using it.
4. Use Settings → Admin Smart Bar to configure options.

== Frequently Asked Questions ==

= How do I open the search bar? =

Press `Ctrl+K` on Windows/Linux or `Cmd+K` on Mac anywhere in the WordPress admin. You can change this keyboard shortcut in Settings → Admin Smart Bar.

= Can I search draft posts and private content? =

Yes. All post statuses are searchable, including drafts and private posts. The plugin respects WordPress capabilities, so users only see content they have permission to access. Draft posts will show a preview link instead of a view link.

= Does it work with WooCommerce? =

Yes. If WooCommerce is installed, you can search products and access WooCommerce admin pages through the search bar.

= How does the fuzzy search work? =

The plugin includes intelligent typo correction. If you mistype a word, it will still find relevant results. For example, searching for "admn" will find "admin" pages.

= Does it track my searches? =

No. All searches happen on your server. No data is sent anywhere. No tracking or analytics are used. The search index is stored locally in your WordPress installation.

= Can I customise the keyboard shortcut? =

Yes. Go to Settings → Admin Smart Bar to choose from:
* Ctrl+K / Cmd+K (default, like VS Code)
* Ctrl+Space / Cmd+Space (like Spotlight)
* Ctrl+/ / Cmd+/ (like Slack)

= What content types can I search? =

You can search posts, pages, media, users, and WooCommerce products (if WooCommerce is installed). You can enable or disable any of these content types in Settings → Admin Smart Bar. Content from popular page builders (Elementor, Divi, Beaver Builder, etc.) is automatically indexed and searchable.

= How is this different from the default WordPress search? =

Admin Smart Bar uses advanced full-text search with BM25 ranking, fuzzy matching, and prefix search for instant type-ahead results. It's significantly faster and more accurate than default WordPress search, and includes admin navigation shortcuts.

= Does it work with page builders like Elementor or Divi? =

Yes. Admin Smart Bar automatically indexes content from 10 popular page builders including Elementor, Divi, Beaver Builder, Bricks, Oxygen, Breakdance, WPBakery, Visual Composer, Thrive Architect, and SeedProd. All page builder content is fully searchable with no configuration needed.

== Screenshots ==

1. Quick search overlay with keyboard shortcuts
2. Search results showing posts, pages, and admin items
3. Plugin settings page
4. Quick actions for edit and view

== Changelog ==

= 1.0.6 =
* Implemented advanced full-text search with SQLite FTS5 and BM25 ranking
* Added fuzzy matching for intelligent typo correction
* Added prefix search for instant type-ahead results
* Implemented capability-based filtering for secure content access
* Added WooCommerce products support
* Improved search result weighting and relevance
* Enhanced database performance with query caching
* Security improvement: Users now searched directly from WordPress database
* Removed sensitive user data from search index
* Bug fixes and performance improvements

= 1.0.0 =
* Initial release
* Fast content search (posts, pages, media, users)
* Admin navigation shortcuts
* Plugin-specific action shortcuts
* Configurable keyboard shortcuts
* Edit and view quick actions

== Upgrade Notice ==

= 1.0.6 =
Major update with advanced full-text search, fuzzy matching, and improved security. Recommended for all users.

= 1.0.0 =
Initial release of Admin Smart Bar.
