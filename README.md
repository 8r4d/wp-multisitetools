# Multisite Tools

A toolkit of network admin utilities for WordPress multisite.

## Install

Copy this folder to `wp-content/plugins/multisite-tools/` and **Network Activate** it from Network Admin › Plugins.

## Settings

**Network Admin › Settings › Multisite Multitools** (also linked from the plugin's row on Network Admin › Plugins) lists every module, grouped into **Network administration**, **Content & publishing**, **Sharing & SEO** and **Security & privacy**, with a checkbox to switch each on or off network-wide. Modules are on by default, including newly added ones, until they're switched off.

## Modules

### Plugin usage

Adds an **Active On** column to Network Admin › Plugins:

- **Network-wide**: network-activated.
- **N sites**: click to expand a list of the sites, each linking to that site's Plugins screen. Archived, spam and deactivated sites are labelled.
- **Not active on any site**: installed but unused, so a candidate for removal.

The plugin-to-site map is cached in a site transient. It's cleared whenever any site's `active_plugins` or `blogname` changes or a site is added, removed or updated, and it expires after 12 hours as a fallback.

### Theme usage

Adds an **Active On** column to Network Admin › Themes, listing the sites using each theme. A theme that's the parent of a child theme also shows **Parent theme on N sites**, since it can't be removed while those sites depend on it. Cached and invalidated the same way as Plugin usage, keyed on each site's `stylesheet` and `template` options.

### QueueBar

Adds a **N Scheduled** item to the admin toolbar on each site, for users who can edit posts, linking to that site's scheduled posts. It's hidden in Network Admin. It uses the same toolbar ID as the standalone QueueBar plugin, so having both active shows a single item.

It also adds a **Posts by Site** widget to Network Admin › Dashboard: a table of every active site (archived, spam and deactivated sites are left out) with its number of published, scheduled and draft posts, plus a totals row. Each count links to that site's filtered Posts screen. The counts are cached in a site transient that's cleared whenever a post changes status or is deleted on any site, a site's name changes, or a site is added, removed or updated, and it expires after an hour as a fallback.

### Default author

Lets each site choose an author who is automatically assigned to newly created posts, so an admin can write while posts are attributed to a lower-privileged account (keeping the admin username off the front end). Set it per site under **Settings › Default Post Author**; the list shows that site's users who can edit posts.

Only new posts of type `post` are affected. If the post is being created with the current user (or no one) as author, the default author is used instead; an explicitly chosen other author is respected. Nothing happens if the chosen user has since been removed from the site.

It uses the same `dpa_default_author` option as the standalone Default Post Author plugin, so existing settings carry over. Deactivate the standalone plugin once this is enabled, or each site will get two settings pages.

### Copy to site

Adds a **Copy to site…** link to each post and page in the Posts and Pages lists. It opens a screen to choose the target site (any other active site you belong to, or any site for super admins), then creates a **draft** copy there with the same title, content, excerpt, categories and tags (created on the target if missing) and featured image (copied into the target's media library). You need to be able to create that kind of content on the target site.

Images and links inside the content are copied as-is, so they still point to the original site. If the featured image file can't be read (e.g. media is offloaded to S3), the copy is made without it and you're told. Custom fields and custom post types aren't copied. If the target site uses Default author, the copy gets that site's default author.

### Social graph

Adds `og:image` and `twitter:image` meta tags (plus `twitter:card` set to `summary_large_image`) to single posts, pages and custom post types, so shared links on Threads, Facebook, X and others show a preview image. It uses the featured image, falling back to the site's **default sharing image** (set per site under **Settings › Reading › Social sharing**), then the site icon; if none exists, no tags are output.

It does nothing on sites running Yoast SEO, Rank Math, All in One SEO, SEOPress or The SEO Framework, which output these tags already. Use the `mst_social_graph_skip` filter to skip it in other cases.

### Hide usernames

Closes the common ways a logged-out visitor can find login names:

- `?author=N` returns a 404 instead of redirecting to `/author/<username>/`.
- The REST API `/wp/v2/users` endpoints return 401.
- The `users` sitemap (`wp-sitemap-users-1.xml`) is removed.
- `author-<username>` body classes and `comment-author-<username>` comment classes are dropped (the ID-based `author-N` class stays).
- Failed logins say the username, email or password is incorrect, without saying which.

Logged-in users are unaffected, so the editor's author picker keeps working. Author archive URLs (`/author/<slug>/`) still use each user's nicename, which defaults to their username. There's no screen for changing it, but WP-CLI can: `wp user update <id> --user_nicename=<new-slug>`.

## Adding a module

1. Create `includes/modules/class-mst-<slug>.php` with a class that has a `register()` method and static `label()`, `description()` and `category()` methods. `category()` returns one of the keys in `MST_Settings::categories()` (add a new category there if none fits). Optionally add an `enable()` method to reset any state when the module is switched back on.
2. Add `'<slug>' => '<Class_Name>'` to `Multisite_Tools::MODULES` in `includes/class-multisite-tools.php`.
