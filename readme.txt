=== Init Reading Position – Remember, Return, Continue ===
Contributors: brokensmile.2103
Tags: scroll, reading, reading progress, usermeta, resume reading
Requires at least: 5.5
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Remembers reading position and auto-scrolls when returning. Works for guests (localStorage) and logged-in users (user meta, per device).

== Description ==

Init Reading Position enhances the reading experience by remembering how far a visitor has read on a post.  
When they return, it automatically scrolls back to where they left off.

Perfect for:

* Long-form articles
* Tutorials or guides
* Web novels or manga
* Any content where readers often stop and come back later

This plugin is part of the [Init Plugin Suite](https://en.inithtml.com/init-plugin-suite-minimalist-powerful-and-free-wordpress-plugins/) — a collection of minimalist, fast, and developer-focused tools for WordPress.

GitHub repository: [https://github.com/brokensmile2103/init-reading-position](https://github.com/brokensmile2103/init-reading-position)

**Features**

* Saves scroll position using localStorage (guests) or user_meta (logged-in users)
* Smart device-based sync: remembers position separately for PC, Mobile, and Tablet
* Automatically scrolls back on page load
* Lightweight, no jQuery, no bloat
* Easy to extend via filters
* Optional settings page to control which post types are enabled

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install via Plugins → Add New.
2. Activate the plugin.
3. Go to **Settings → Reading Position** and choose which post types should support this feature.

== Frequently Asked Questions ==

= Does it sync across devices? =  
Yes. For logged-in users, scroll position is saved in user_meta, and stored separately for each device type (PC, Mobile, Tablet).

= Will it work with custom post types? =  
Yes. You can enable it for any public post type in the plugin settings page.

= Will it slow down my site? =  
No. It only runs a small JS script on enabled single pages and stores data efficiently.

== Screenshots ==

1. Simple settings page — choose post types and optionally enter a CSS selector (e.g. `.entry-content`) to limit where reading progress is tracked.

== Changelog ==

= 1.2 – November 12, 2025 =
- Added: Support for multiple CSS selectors separated by commas (e.g. `.entry-content, .post-content, #main`)
- Added: Option “Auto-clear saved position at content end” (enabled by default)
- Improved: Scroll tracking now activates if the reader is inside *any* of the configured selector areas
- Improved: Percent calculation prioritizes the selector in scope, falls back to whole page when outside all selectors
- Behavior: When auto-clear is enabled, progress is cleared at the end of the content area; when disabled, it falls back to clearing at page end
- Zero breaking changes — existing settings, localStorage keys, and user_meta structure remain compatible

= 1.1 – November 5, 2025 =
- Added: CSS Selector option — plugin now only tracks progress inside the selected content area (e.g. `.entry-content`)
- Added: i18n ready strings for new settings field (English + Vietnamese translations included)
- Improved: Scroll position tracking logic — no longer saves progress when user scrolls outside the selected content area (e.g. comments section)
- Improved: Cleanup behavior — scroll progress is deleted only when reaching end of the page, never based on selector range
- No breaking changes — keeps old localStorage keys and user meta structure
- Updated: Admin settings page UI/UX (placeholder instead of default selector)

= 1.0 – May 20, 2025 =
- Initial release
- Saves scroll position using localStorage (guests) and user meta (logged-in users)
- Auto-scrolls back to last position on load
- Per-device storage (PC, Mobile, Tablet)
- Settings page to choose post types
- Filter `init_plugin_suite_reading_position_delay` to adjust debounce time

== License ==

This plugin is licensed under the GPLv2 or later.  
You are free to use, modify, and distribute it under the same license.
