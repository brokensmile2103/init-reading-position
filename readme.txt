=== Init Reading Position – Remember, Return, Continue ===
Contributors: brokensmile.2103
Tags: scroll, reading, reading progress, resume reading, reading position
Requires at least: 5.5
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Remembers reading position and auto-scrolls when returning. Works for guests (localStorage) and logged-in users (custom DB table, per device).

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

* Saves scroll position using localStorage (guests) or a dedicated database table (logged-in users)
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
Yes. For logged-in users, scroll position is stored in a dedicated database table, saved separately for each device type (PC, Mobile, Tablet).

= Will it work with custom post types? =
Yes. You can enable it for any public post type in the plugin settings page.

= Will it slow down my site? =
No. It only runs a small JS script on enabled single pages and stores data efficiently in a dedicated table with indexed queries.

== Screenshots ==

1. Simple settings page — choose post types and optionally enter a CSS selector (e.g. `.entry-content`) to limit where reading progress is tracked.

== Changelog ==

= 1.8 – August 2, 2026 =
- Fixed: The frontend script could fail to enqueue on every page of the site whenever the option controlling enabled post types had never been saved (fresh installs, or sites where the admin never opened Settings) — a mismatched default value caused the enabled post-type list to resolve to empty instead of the intended `post` default
- Fixed: Reading position saves could silently fail intermittently ("works, then doesn't") — the script previously marked a position as synced as soon as a save request was sent, without waiting for server confirmation; combined with server-side rate limiting on sites with a persistent object cache (Redis/Memcached), some saves were silently dropped while the script believed they had succeeded, resulting in stale or incorrect resume positions
- Added: The final save sent when a tab is hidden/closed now bypasses server-side rate limiting and always writes straight to the database — this is the last chance to persist unsaved progress, so it's no longer subject to being silently throttled or deferred away
- Improved: Save requests to the REST API now return an explicit `throttled` status instead of a false "success" when rate-limited, so the script can correctly retry instead of assuming the position was saved
- Added: On sites with a persistent object cache, heartbeat saves (the periodic safety-net sync sent while a reader keeps scrolling forward, as opposed to a real re-read reversal or the final save) are now written to cache instead of the database. This cuts sustained database write load dramatically on high-concurrency sites — e.g. a manga/webtoon theme with tens of thousands of simultaneously reading logged-in users — while page reloads still resume from the correct position, since reads are cache-first. Progress is always promoted to a real, durable database write at the next meaningful checkpoint (re-read reversal or tab close). Sites without a persistent object cache are unaffected and continue writing straight to the database, same as before
- Added: The heartbeat interval (default 30s) is now filterable via `init_plugin_suite_reading_position_heartbeat`, letting high-traffic sites widen the safety-net cadence to further reduce request volume at peak hours without editing plugin files
- No breaking changes — REST API, database schema, localStorage keys, and frontend behavior remain fully compatible

= 1.7 – August 1, 2026 =
- Added: Behavior-based sync engine replacing the fixed 5-second timer — the script now only talks to the server on meaningful checkpoints (a real upward reversal while re-reading, an occasional heartbeat while continuously reading forward, or right before the tab is hidden/closed), cutting network requests dramatically for the common case of a reader scrolling straight through an article
- Improved: The furthest scroll position ever reached is what gets persisted, not the current position — scrolling back up to re-read a section never regresses the saved/resume point, for both logged-in users and guests (localStorage)
- Improved: Scroll and resize listeners are now registered as passive — the browser no longer waits on the handler before scrolling, removing a source of jank on scroll-heavy pages
- Improved: Full-page scroll height is now cached alongside scoped-element bounds instead of being read on every debounced scroll tick — avoids repeated layout-forcing reads; refreshed on resize and once after full page load
- Added: Best-effort final save on tab hide/close (`pagehide` / `visibilitychange`) using `navigator.sendBeacon` where available — the last known position is no longer lost when a reader closes the tab between two sync checkpoints
- Improved: Reading position lookups now skip the legacy user_meta fallback entirely once the user_meta → database migration is confirmed complete — removes 1-2 unnecessary lookups per uncached request on the vast majority of sites
- Improved: Removed a redundant `user_id` database index — it duplicated the leftmost prefix of the existing `user_post_device` unique key, so dropping it removes unnecessary index-maintenance overhead on every write with no read-performance impact. Existing sites get this cleaned up automatically and safely, exactly once, on upgrade
- No breaking changes — REST API, database schema (aside from the redundant index removal above), localStorage keys, and frontend behavior remain fully compatible

= 1.6 – April 23, 2026 =
- Improved: Reading position retrieval is now handled via a single bulk query instead of multiple per-device lookups — reduces database queries and improves performance for logged-in users
- Added: Bulk cache layer for multi-device reading positions with a deterministic cache key (`pc_mobile_tablet`) — ensures consistent cache hits and simplifies invalidation logic
- Improved: Cache invalidation now clears both single-device and bulk cache entries — prevents stale data when users continue reading across sessions
- Improved: Data flow refactored to return all device positions (`pc`, `mobile`, `tablet`) in one payload — frontend now selects the correct device slot dynamically
- Fixed: Inconsistent cache key generation (previous md5-based keys) that could lead to stale or non-invalidated cache entries
- Fixed: Potential PHP notice when `savedPositions` was undefined for guest users — now always initialized with default values
- Improved: Internal code cleanup and minor optimizations for better maintainability and consistency
- No breaking changes — REST API, database schema, localStorage keys, and frontend behavior remain fully compatible

= 1.5 – April 23, 2026 =
- Improved: Device detection is now handled entirely by JavaScript — PHP no longer performs UA sniffing on the server side, eliminating mismatches between detected device on page load vs. save
- Improved: Saved scroll position lookup now tries all three device slots (pc, mobile, tablet) in order, so the correct position is always restored regardless of which device the user was last on
- Improved: Scope element bounds (`absTop`, `absBottom`) are now cached after DOMContentLoaded and refreshed on window resize — `getBoundingClientRect()` is no longer called on every scroll event
- Improved: REST API rate limiting switched from transient-based to `wp_cache`-based — eliminates transient writes to `wp_options` on high-traffic sites; works best with an object cache (Redis/Memcached), degrades gracefully without one
- Fixed: `autoClearOnEnd` with no CSS selector configured would never trigger the end-of-content clear; now correctly falls back to page-bottom detection in that case
- No breaking changes — REST API, localStorage keys, and all existing settings remain fully compatible

= 1.4 – April 21, 2026 =
- Refactored: Migration now runs via a self-scheduling WP-Cron event instead of admin_init
- Added: Automatic re-scheduling every 30 seconds until migration completes
- Improved: Removed dependency on admin requests for background processing
- Improved: Added locking to prevent overlapping migration runs
- No breaking changes

= 1.3 – April 21, 2026 =
- Added: Switched from user_meta storage to a dedicated custom database table for reading positions
- Added: Automatic migration from existing user_meta data to the new database structure (runs safely in the background)
- Added: Optimized database schema with unique keys (user_id, post_id, device) for efficient upsert operations
- Improved: Faster read/write performance and better scalability for large sites
- Improved: Reduced API request frequency by adding a throttle layer on top of existing debounce
- Improved: Device detection now uses normalized lowercase values (`pc`, `mobile`, `tablet`) for full backend consistency
- Improved: Added fail-safe handling for REST requests to prevent JavaScript errors on network issues
- Performance: Significantly reduced server load for logged-in users and improved overall responsiveness
- Backward-compatible: Existing user_meta data is migrated seamlessly, with fallback support during transition
- No breaking changes — REST API, localStorage keys, and frontend behavior remain fully compatible

= 1.2 – November 12, 2025 =
- Added: Support for multiple CSS selectors separated by commas (e.g. `.entry-content, .post-content, #main`)
- Added: Option "Auto-clear saved position at content end" (enabled by default)
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
