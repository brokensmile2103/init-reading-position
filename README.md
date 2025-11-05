# Init Reading Position – Remember, Return, Continue
> Remember where readers left off — and automatically scroll back when they return.

**Auto-resume reading. Per-device sync. No jQuery. No bloat.**

[![Version](https://img.shields.io/badge/stable-v1.1-blue.svg)](https://wordpress.org/plugins/init-reading-position/)
[![License](https://img.shields.io/badge/license-GPLv2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
![Made with ❤️ in HCMC](https://img.shields.io/badge/Made%20with-%E2%9D%A4%EF%B8%8F%20in%20HCMC-blue)

## Overview

**Init Reading Position** enhances the reading experience by automatically restoring scroll position when readers return to a post.

- Guests → saved via `localStorage`
- Logged-in users → saved in `user_meta`, per device (PC / Mobile / Tablet)

Perfect for long articles, tutorials, documentation, web novels, or any content that users frequently return to.

## Features

- Automatically saves scroll position while reading
- Auto-resume when visiting the same post again
- Per-device sync (PC / Mobile / Tablet)
- Uses `localStorage` for guests and `user_meta` for logged-in users
- Minimal, native JavaScript (no jQuery, no dependencies)
- Optional settings page to choose enabled post types
- Developer-friendly filters for customization
- Translation-ready (`.pot` file included)

## How It Works

1. Reader scrolls → position is saved (debounced)
2. Reader returns later → page auto-scrolls to the saved position
3. No UI, no popups — purely seamless experience

## Settings

Go to:

```
Settings → Reading Position
```

Enable the feature for any public post type (posts, pages, custom post types like *manga*, *docs*, *tutorials*, etc.).

## Developer Filters

| Filter | Description | Params |
|--------|-------------|---------|
| `init_plugin_suite_reading_position_delay` | Controls debounce delay (ms) when saving scroll position | `int $milliseconds` |

Example: change debounce from 500ms to 200ms

```php
add_filter( 'init_plugin_suite_reading_position_delay', fn() => 200 );
```

## Installation

1. Upload to `/wp-content/plugins/`
2. Activate under **Plugins → Init Reading Position**
3. Go to **Settings → Reading Position** to enable post types

No shortcode. No widget. It just works.

## License

GPLv2 or later — open source, minimal, developer-first.

## Part of Init Plugin Suite

Init Content Protector is part of the [Init Plugin Suite](https://en.inithtml.com/init-plugin-suite-minimalist-powerful-and-free-wordpress-plugins/) — a collection of blazing-fast, no-bloat plugins made for WordPress developers who care about quality and speed.
