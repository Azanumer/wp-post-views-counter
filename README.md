# Post Views Counter

A lightweight, dependency-free WordPress plugin that counts real post views — no cookies, no external analytics, no JavaScript beacon.

## Why this instead of a heavy analytics plugin?

- **Bot-aware:** common crawler / scraper user-agents are excluded, so counts reflect readers, not bots.
- **Inflation-proof:** logged-in users are excluded by default, so your own editing and previewing never inflates counts.
- **Race-safe:** increments happen with a single SQL `UPDATE`, not read-modify-write, so concurrent readers can't lose counts.
- **Zero front-end cost:** counting runs on `template_redirect`; no extra script is loaded for visitors.

## Features

- Counts views for published singular posts (optionally pages and custom post types).
- Sortable **Views** column on the post/page list screens.
- `[popular_posts count="5" post_type="post" show_views="1"]` shortcode for a most-read list.
- `wpvc_get_views( $post_id )` helper for themes.
- Settings under **Settings → Post Views**: count pages, exclude logged-in users, exclude bots.

## Install

1. Upload the plugin folder to `wp-content/plugins/` and activate it.
2. Views start counting immediately; existing posts show 0 until their first counted view.

## Uninstall

Removes the `wpvc_settings` option and all `_wpvc_views` postmeta.

## License

MIT
