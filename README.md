# Init Reading Position – Remember, Return, Continue

> Remember where readers left off — and automatically scroll back when they return.

**Auto-resume reading. Per-device sync. No jQuery. No bloat.**

[![Version](https://img.shields.io/badge/stable-v1.5-blue.svg)](https://wordpress.org/plugins/init-reading-position/)
[![License](https://img.shields.io/badge/license-GPLv2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
![Made with ❤️ in HCMC](https://img.shields.io/badge/Made%20with-%E2%9D%A4%EF%B8%8F%20in%20HCMC-blue)

## Overview

**Init Reading Position** enhances the reading experience by automatically restoring scroll position when readers return to a post.

- Guests → saved via `localStorage`
- Logged-in users → saved in a dedicated database table, per device (PC / Mobile / Tablet)

Perfect for long articles, tutorials, documentation, web novels, or any content that users frequently return to.

## Features

- Automatically saves scroll position while reading
- Auto-resume when visiting the same post again
- Per-device sync (PC / Mobile / Tablet)
- Uses `localStorage` for guests and a dedicated DB table for logged-in users
- Minimal, native JavaScript (no jQuery, no dependencies)
- Optional CSS selector to limit tracking to a specific content area
- Auto-clears saved position when reader reaches the end of the content
- Optional settings page to choose enabled post types
- Developer-friendly filters for customization
- Translation-ready (`.pot` file included)

## How It Works

1. Reader scrolls → position is saved (debounced, max once per 5 seconds)
2. Reader returns later → page auto-scrolls to the saved position
3. Reader finishes the post → saved position is cleared automatically
4. No UI, no popups — purely seamless experience

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
| `init_plugin_suite_reading_position_delay` | Debounce delay (ms) when saving scroll position | `int $milliseconds` |
| `init_plugin_suite_reading_position_rate_limit` | Minimum seconds between server-side saves per user | `int $seconds` |
| `init_plugin_suite_reading_position_enabled_types` | Override enabled post types at runtime | `array $post_types` |
| `init_plugin_suite_reading_position_localized_data` | Modify data passed to the JS script | `array $data, int $post_id` |
| `init_plugin_suite_reading_position_data_to_store` | Modify scroll data before saving to DB | `array $data, int $post_id, string $device, int $user_id` |
| `init_plugin_suite_reading_position_should_delete` | Control whether a position should be cleared | `bool, int $post_id, string $device, int $user_id` |

Example: change debounce from 1000ms to 500ms

```php
add_filter( 'init_plugin_suite_reading_position_delay', fn() => 500 );
```

## Installation

1. Upload to `/wp-content/plugins/`
2. Activate under **Plugins → Init Reading Position**
3. Go to **Settings → Reading Position** to enable post types

No shortcode. No widget. It just works.

## License

GPLv2 or later — open source, minimal, developer-first.

## Part of Init Plugin Suite

Init Reading Position is part of the [Init Plugin Suite](https://en.inithtml.com/init-plugin-suite-minimalist-powerful-and-free-wordpress-plugins/) — a collection of blazing-fast, no-bloat plugins made for WordPress developers who care about quality and speed.
