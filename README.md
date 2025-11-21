# Admin Smart Bar

A lightning-fast command palette for WordPress admin powered by advanced full-text search. Navigate your WordPress site instantly with keyboard shortcuts.

## What is Admin Smart Bar?

Admin Smart Bar brings Mac Spotlight-style search to your WordPress admin. Powered by advanced full-text search technology with fuzzy matching and intelligent ranking, it delivers instant, accurate results as you type.

Instead of clicking through menus, just press `Ctrl+K` (or `Cmd+K` on Mac), type what you're looking for, and go straight there.

## Features

### Advanced full-text search
Powered by SQLite FTS5 with BM25 ranking for lightning-fast, relevant results:
- Posts and pages (including drafts and private content)
- Page builder content (Elementor, Divi, Beaver Builder, and 7 more)
- Media files (images, videos, documents)
- Users (with capability-based filtering)
- WooCommerce products (if WooCommerce is installed)
- Fuzzy matching for typo correction
- Prefix search for instant type-ahead results
- Intelligent content weighting (titles ranked higher than content)

### Smart admin navigation
Find WordPress admin pages instantly:
- Dashboard, posts, pages, media
- Comments, appearance, plugins, users
- Settings, tools, and more
- All submenu items and custom post types

### Popular plugin shortcuts
Quickly access admin pages for installed plugins:
- WooCommerce (products, orders, settings, analytics)
- Yoast SEO (settings, search appearance, tools)
- Elementor (templates, settings)
- Advanced Custom Fields (field groups)
- Contact Form 7, WPForms, Jetpack, and more

### Keyboard-first design
- `Ctrl+K` or `Cmd+K` - Open search
- `↑` `↓` - Navigate results
- `Enter` - Open selected item
- `Esc` - Close search

### Quick actions
Every search result shows quick actions:
- **Edit** - Jump to edit screen
- **View** - Open frontend view (published posts) or preview (drafts)

## Installation

1. Download the plugin
2. Upload to `/wp-content/plugins/admin-smart-bar/`
3. Activate through the 'Plugins' menu in WordPress
4. Press `Ctrl+K` (or `Cmd+K` on Mac) to start using it

## How to use

### Basic search
1. Press `Ctrl+K` (or `Cmd+K` on Mac) anywhere in WordPress admin
2. Type what you're looking for
3. Use arrow keys to navigate results
4. Press `Enter` to open the selected item

### Search examples

**Finding content:**
- Type "contact" to find your Contact page
- Type "products" to find WooCommerce products
- Type "john" to find posts or users named John
- Type "draft" to find draft posts

**Admin navigation:**
- Type "plugins" to go to the Plugins page
- Type "settings" to see all settings pages
- Type "new post" to create a new post
- Type "theme" to access theme options
- Type "users" to manage users

**Plugin actions:**
- Type "woocommerce orders" to view WooCommerce orders
- Type "yoast seo" to access Yoast SEO settings
- Type "acf" to manage Advanced Custom Fields

**Fuzzy search examples:**
- Type "admn" to find admin pages (typo correction)
- Type "setings" to find settings (automatic correction)
- Type "prodct" to find products (fuzzy matching)

### Quick actions
Each search result has quick action links:
- **Edit** - Opens the item in the editor
- **View** - Opens the published page or preview (for drafts)

Click these links or just click anywhere on the result to edit.

## Settings

Go to **Settings → Admin Smart Bar** to configure:

### Keyboard shortcut
Choose your preferred shortcut:
- `Ctrl+K` / `Cmd+K` (default, like VS Code)
- `Ctrl+Space` / `Cmd+Space` (like Spotlight)
- `Ctrl+/` / `Cmd+/` (like Slack)

### Search scope
Choose what content types to search:
- ✓ Posts
- ✓ Pages
- ✓ Media
- ✓ Users
- ✓ Products (WooCommerce)

Uncheck any content type you don't want to search. The search index automatically updates when you save or delete content.

### Page builder support
Admin Smart Bar automatically indexes content from these popular page builders:
- Elementor
- Divi Builder
- Beaver Builder
- Bricks Builder
- Oxygen Builder
- Breakdance
- WPBakery Page Builder
- Visual Composer
- Thrive Architect
- SeedProd

Content created with these page builders is fully searchable, with all markup and shortcodes automatically stripped. No configuration needed.

## Tips and tricks

### Search page builder content
If you use page builders like Elementor or Divi, all your page builder content is automatically indexed and searchable. Just search for any text that appears on your pages, and Admin Smart Bar will find it.

### Use natural language
You don't need to type exact titles. The fuzzy search understands what you mean:
- "new user" finds "Create a new user"
- "backup" finds backup-related plugins and pages
- "cache" finds caching plugin settings
- "order" finds WooCommerce orders

### Typo correction works automatically
Made a typo? No problem:
- "admn" automatically corrects to "admin"
- "setings" finds "settings"
- "prodct" finds "product"

### Search by email
Type part of an email address to find users:
- "john@example.com" finds that user
- "@gmail.com" finds all Gmail users

### Find drafts and private content
Unpublished posts and pages appear in search results based on your capabilities. The View link shows a preview for drafts instead of the published page.

### Instant type-ahead
Results appear as you type thanks to prefix search. Start typing and see matching results immediately.

### Skip the mouse
Once you learn the keyboard shortcuts, you can navigate WordPress without touching your mouse.

## Requirements

- WordPress 5.0 or higher
- PHP 7.2 or higher
- Works on all modern browsers

## Support

Need help? Found a bug?

- **Email:** support@codevera.ai
- **GitHub:** [Report an issue](https://github.com/codevera-ai/admin-smart-bar/issues)

## Privacy

Admin Smart Bar:
- Does not collect any data
- Does not make external requests
- All searches happen on your server
- No tracking or analytics

## Credits

Built by Codevera - https://codevera.ai
