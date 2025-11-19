# Admin Smart Bar

A fast, searchable command palette for WordPress admin. Navigate your WordPress site like a pro with keyboard shortcuts and instant search.

## What is Admin Smart Bar?

Admin Smart Bar brings Mac Spotlight-style search to your WordPress admin. Instead of clicking through menus, just press `Ctrl+K` (or `Cmd+K` on Mac), type what you're looking for, and go straight there.

## Features

### Lightning-fast search
Search across all your WordPress content:
- Posts and pages (including draft previews)
- Media files
- Users
- Page builder content (Elementor, Beaver Builder, Divi, Oxygen)

### Smart admin navigation
Find WordPress admin pages instantly:
- Dashboard, posts, pages, media
- Comments, appearance, plugins, users
- Settings, tools, and more
- All submenu items included

### Plugin shortcuts
If you have popular plugins installed, search their admin pages too:
- WooCommerce (products, orders, settings, analytics)
- Yoast SEO (settings, search appearance, tools)
- Elementor (templates, settings)
- Advanced Custom Fields (field groups)
- And many more...

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
- Type "products" to find product-related pages
- Type "john" to find posts or users named John

**Admin navigation:**
- Type "plugins" to go to the Plugins page
- Type "settings" to see all settings pages
- Type "new post" to create a new post
- Type "theme" to access theme options

**Plugin actions:**
- Type "woocommerce orders" to view WooCommerce orders
- Type "yoast seo" to access Yoast SEO settings
- Type "elementor" to see Elementor templates

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
Choose what to search:
- ✓ Posts
- ✓ Pages
- ✓ Media
- ✓ Users

Uncheck any you don't want to search.

## Page builder support

Admin Smart Bar automatically detects and searches content from popular page builders:

- **Elementor** - Searches widget content, headings, text, etc.
- **Beaver Builder** - Searches module content
- **Divi Builder** - Searches Divi sections and modules
- **Oxygen Builder** - Searches shortcode content

If a page builder is not installed, the plugin doesn't add any overhead.

## Tips and tricks

### Use natural language
You don't need to type exact titles:
- "new user" finds "Create a new user"
- "backup" finds UpdraftPlus backup pages
- "cache" finds WP Rocket settings

### Search by email
Type part of an email address to find users:
- "john@example.com" finds that user
- "@gmail.com" finds all Gmail users

### Find drafts
Unpublished posts and pages appear in search results. The View link shows a preview instead of the published page.

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
