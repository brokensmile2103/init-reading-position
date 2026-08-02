# Init Reading Position – Remember, Return, Continue

> Remember where readers left off — and automatically scroll back when they return.

**Auto-resume reading. Per-device sync. Built for scale. No jQuery. No bloat.**

[![Version](https://img.shields.io/badge/stable-v1.8-blue.svg)](https://wordpress.org/plugins/init-reading-position/)
[![License](https://img.shields.io/badge/license-GPLv2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
![Made with ❤️ in HCMC](https://img.shields.io/badge/Made%20with-%E2%9D%A4%EF%B8%8F%20in%20HCMC-blue)

## Overview

**Init Reading Position** enhances the reading experience by automatically restoring scroll position when readers return to a post.

- Guests → saved via `localStorage`
- Logged-in users → saved in a dedicated database table, per device (PC / Mobile / Tablet)

Perfect for long articles, tutorials, documentation, web novels, manga/webtoon readers, or any content that users frequently return to — including sites with tens of thousands of concurrently reading logged-in users.

## Features

- Automatically saves scroll position while reading
- Auto-resume when visiting the same post again
- Per-device sync (PC / Mobile / Tablet)
- Behavior-based sync engine — talks to the server on meaningful checkpoints instead of a fixed timer, so a straight-through read fires close to one request total
- Cache-backed heartbeat writes on sites with a persistent object cache (Redis/Memcached) — durable saves still land in the database, but the frequent safety-net pings never hit MySQL, keeping the database quiet even at very high concurrency
- Best-effort final save on tab close (`sendBeacon`) so the last few seconds of reading are never lost
- Uses `localStorage` for guests and a dedicated DB table for logged-in users
- Minimal, native JavaScript (no jQuery, no dependencies)
- Optional CSS selector to limit tracking to a specific content area
- Auto-clears saved position when reader reaches the end of the content
- Optional settings page to choose enabled post types
- Developer-friendly filters for customization
- Translation-ready (`.pot` file included)

## How It Works

Instead of polling the server every few seconds, the frontend script only syncs on checkpoints that actually matter:

1. **Reading forward** → tracked locally only; an occasional heartbeat (every 30s by default) acts as a crash-safety net
2. **Scrolling back up to re-read** → the furthest point ever reached is synced immediately — resuming never regresses to an earlier position
3. **Tab hidden or closed** → a final save flushes any unsynced progress via `sendBeacon`, bypassing rate limits since it's the last chance to persist
4. **Returning later** → the page auto-scrolls to the saved position
5. **Reaching the end of the post** → the saved position is cleared automatically

No UI, no popups — purely seamless. And on high-traffic sites with a persistent object cache, the frequent heartbeat checkpoint writes to cache instead of the database, while reversal and final-save checkpoints always write through to the database — so accuracy never trades away for scale.

## Settings

Go to:

```
Settings → Reading Position
```

Enable the feature for any public post type (posts, pages, custom post types like *manga*, *docs*, *tutorials*, etc.).

Optionally enter a CSS selector (e.g. `.entry-content`) to restrict scroll tracking to a specific content area only.

## Developer Filters

| Filter | Description | Params |
|--------|-------------|--------|
| `init_plugin_suite_reading_position_delay` | Debounce delay (ms) for the scroll listener | `int $milliseconds` |
| `init_plugin_suite_reading_position_heartbeat` | Interval (ms) between safety-net heartbeat syncs while reading forward — widen this on very high-traffic sites to reduce request volume further | `int $milliseconds` |
| `init_plugin_suite_reading_position_rate_limit` | Minimum seconds between database-writing saves per user (only applies when a persistent object cache is active) | `int $seconds` |
| `init_plugin_suite_reading_position_enabled_types` | Override enabled post types at runtime | `array $post_types` |
| `init_plugin_suite_reading_position_localized_data` | Modify data passed to the JS script | `array $data, int $post_id` |
| `init_plugin_suite_reading_position_data_to_store` | Modify scroll data before saving (applies to both cache-only heartbeat writes and durable database writes) | `array $data, int $post_id, string $device, int $user_id` |
| `init_plugin_suite_reading_position_should_delete` | Control whether a position should be cleared | `bool, int $post_id, string $device, int $user_id` |

Example: widen the heartbeat interval to 60s on a very high-traffic site

```php
add_filter( 'init_plugin_suite_reading_position_heartbeat', fn() => 60000 );
```

Example: change the scroll debounce from 1000ms to 500ms

```php
add_filter( 'init_plugin_suite_reading_position_delay', fn() => 500 );
```

## Built for Scale

At high concurrency — think a manga/webtoon theme at peak hours with tens of thousands of logged-in readers scrolling at once — the sync engine's cache-backed heartbeat means the vast majority of routine "still reading" pings never touch the database at all. Only the checkpoints that actually need durability (a genuine re-read, or the reader leaving) write through, so database load stays proportional to real engagement, not to raw concurrent traffic. Sites without a persistent object cache are unaffected and continue writing straight to the database, exactly as before.

## Installation

1. Upload to `/wp-content/plugins/`
2. Activate under **Plugins → Init Reading Position**
3. Go to **Settings → Reading Position** to enable post types

No shortcode. No widget. It just works.

## License

GPLv2 or later — open source, minimal, developer-first.

## Part of Init Plugin Suite

Init Reading Position is part of the [Init Plugin Suite](https://en.inithtml.com/init-plugin-suite-minimalist-powerful-and-free-wordpress-plugins/) — a collection of blazing-fast, no-bloat plugins made for WordPress developers who care about quality and speed.
