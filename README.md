# Multisite Tools

A toolkit of network admin utilities for WordPress multisite.

## Install

Copy this folder to `wp-content/plugins/multisite-tools/` and **Network Activate** it from Network Admin › Plugins.

## Settings

**Network Admin › Settings › Multisite Multitools** (also linked from the plugin's row on Network Admin › Plugins) has a tab for each module category (**Network administration**, **Content & publishing**, **Sharing & SEO**, **Blocks** and **Security & privacy**), each listing its modules with a checkbox to switch them on or off network-wide. Each tab saves only its own modules. Modules are on by default, including newly added ones, until they're switched off. The exception is the hardening switches on the Security & privacy tab (Disable XML-RPC, Disable file editor, Hide WordPress version), which start off because they can change how sites behave.

The **Site colours** tab sets a colour for each active site, using the standard WordPress colour picker. The colour marks the site wherever the plugin lists sites: the Calendar, the Posts by Site widget, the Plugin and Theme usage columns, the Copy to site confirmation and, with the Toolbar site colours module, the admin toolbar. Sites without a custom colour get a default from a 10-colour palette based on their site ID, so a site has the same colour on every screen and for every user. **Default** in the picker clears a custom colour. Deleting a site removes its colour.

## Modules

### Plugin usage

Adds an **Active On** column to Network Admin › Plugins:

- **Network-wide**: network-activated.
- **N sites**: click to expand a list of the sites, each linking to that site's Plugins screen. Archived, spam and deactivated sites are labelled.
- **Not active on any site**: installed but unused, so a candidate for removal.

The plugin-to-site map is cached in a site transient. It's cleared whenever any site's `active_plugins` or `blogname` changes or a site is added, removed or updated, and it expires after 12 hours as a fallback.

### Theme usage

Adds an **Active On** column to Network Admin › Themes, listing the sites using each theme. A theme that's the parent of a child theme also shows **Parent theme on N sites**, since it can't be removed while those sites depend on it. Cached and invalidated the same way as Plugin usage, keyed on each site's `stylesheet` and `template` options.

### Site overview

Adds **Network Admin › Sites › Overview**: a table of every active site with its last published post and next scheduled post (with "3 days ago" / "in 2 days"), counts of published, scheduled and draft posts and comments awaiting moderation (each linking to the filtered screen on that site), and its theme. Sites are flagged when they:

- have **missed a scheduled post**,
- are **hidden from search engines** (Settings › Reading › Search engine visibility),
- have published nothing in **6 months**, or
- have **comments to moderate**.

A summary above the table counts each kind of problem, or says everything looks healthy. The figures are read fresh on each visit with two queries per 100 sites.

### Post type inventory

Adds **Network Admin › Sites › Post Types**, listing every custom post type on the network with the sites that register it and the sites that have content in it (with post counts). It also lists **orphaned content**: posts on a site whose post type isn't registered there, usually left behind by a removed or deactivated plugin or theme. Orphaned posts can't be seen or edited on their site, but they still take up space and turn up in exports and some queries. Each orphan is labelled:

- **Still registered on N other sites**: the plugin is probably just deactivated on this site. Reactivating it brings the content back, so that may be the better fix.
- **Not registered anywhere**: the plugin or theme is probably gone for good.

Post types are registered in code on each request, so Network Admin can't see another site's directly. Instead, each site records its registered post types in a `mst_post_types` option whenever they change, keeping admin and front-end requests separate because some plugins register post types in only one of them. A site that hasn't been visited since the module was switched on has no record yet. It's never reported as having orphans and is listed in a warning instead. **Refresh all sites** pings every active site's `wp-cron.php`, which loads that site's plugins and theme so it reports in, in the background; reload the page a minute later. Switching the module off and on again discards the old records, since post types may have been added in the meantime.

**Delete…** on an orphan opens a dry run showing how many posts, revisions, custom fields, comments, term assignments and attached media items are affected. Type the post type's name to confirm, and the posts are permanently deleted in batches of 50 with `wp_delete_post()`, along with their revisions, custom fields, comments and term assignments (term counts are updated). Attached media is kept and detached. The page re-checks that the type is still unregistered before every batch. There's no undo, so back up the site's database first. Only super admins (`manage_network`) can see the page or delete content. Archived, spam and deactivated sites aren't checked, since they can't report their post types.

### Network search

Adds **Network Admin › Dashboard › Search** (also in the toolbar under My Sites › Network Admin): search post and page titles across every active site, optionally including content, filtered by type and status. Results show the site (with its colour), type, status and date, newest first, with the match highlighted and **Edit** and **View**/**Preview** links. Up to 50 results per site are shown.

### Toolbar site colours

Shows each site's colour (from the **Site colours** tab) in the admin toolbar:

- a coloured bar down the left edge of every site in the **My Sites** menu, and
- a 3px strip along the bottom of the toolbar on the site you're on, in the admin and on the front end, so it's obvious which site you're editing. Network Admin has no strip, since it isn't a site.

### QueueBar

Adds a **N Scheduled** item to the admin toolbar on each site, for users who can edit posts, linking to that site's scheduled posts. It's hidden in Network Admin. It uses the same toolbar ID as the standalone QueueBar plugin, so having both active shows a single item.

It also adds a **Posts by Site** widget to Network Admin › Dashboard: a table of every active site (archived, spam and deactivated sites are left out) with its number of published, scheduled and draft posts, plus a totals row. Each count links to that site's filtered Posts screen. The counts are cached in a site transient that's cleared whenever a post changes status or is deleted on any site, a site's name changes, or a site is added, removed or updated, and it expires after an hour as a fallback.

### Default author

Lets each site choose an author who is automatically assigned to newly created posts, so an admin can write while posts are attributed to a lower-privileged account (keeping the admin username off the front end). Set it per site under **Settings › Default Post Author**; the list shows that site's users who can edit posts.

Only new posts of type `post` are affected. If the post is being created with the current user (or no one) as author, the default author is used instead; an explicitly chosen other author is respected. Nothing happens if the chosen user has since been removed from the site.

It uses the same `dpa_default_author` option as the standalone Default Post Author plugin, so existing settings carry over. Deactivate the standalone plugin once this is enabled, or each site will get two settings pages.

### Calendar

Adds **Dashboard › Calendar** in Network Admin and on every site, covering every site you can edit posts on (all active sites for super admins):

- **Month**: a calendar grid of published and scheduled posts, colour-coded by site, with a site filter and a legend. Scheduled posts have a dashed outline; missed ones a red edge. Click a post for a popup with its site, status, date and author, plus **Edit** and **View**/**Preview** links.
- **Agenda**: everything scheduled from now on, grouped by day ("Today", "Tomorrow", then dates), with any missed scheduled posts flagged at the top.

Posts appear at their own site's local date and time, since sites can be in different time zones. Only the `post` post type is shown (see `MST_Calendar::POST_TYPES`), drafts aren't included, and each site contributes at most 500 posts per view. Posts are read with one indexed query per 100 sites, so the page stays quick on large networks.

### Copy to site

Adds a **Copy to site…** link to each post and page in the Posts and Pages lists. It opens a screen to choose the target site (any other active site you belong to, or any site for super admins), then creates a **draft** copy there with the same title, content, excerpt, categories and tags (created on the target if missing) and featured image (copied into the target's media library). You need to be able to create that kind of content on the target site.

Images and links inside the content are copied as-is, so they still point to the original site. If the featured image file can't be read (e.g. media is offloaded to S3), the copy is made without it and you're told. Custom fields and custom post types aren't copied. If the target site uses Default author, the copy gets that site's default author.

### Missed schedule fixer

Publishes scheduled posts that WordPress missed. WP-Cron only runs on a site when that site gets a visit, so a quiet site can sit on an overdue post until someone happens by.

- **Scan:** a visit to any site, at most every 5 minutes network-wide, checks every active site for posts more than a minute overdue (one UNION query per 100 sites) and pings each late site's `wp-cron.php` in the background, without slowing the visit.
- **Publish:** whenever a site's cron runs (pinged or natural), it publishes up to 20 of its own overdue posts. This also catches posts whose scheduled event was lost, which cron alone never publishes. Publishing on the site itself means plugins active only on that site (auto-posters, newsletters) still see the post go live.

It still needs *some* traffic somewhere on the network, and the host must allow WordPress to make requests to its own sites (the same requirement as normal WP-Cron). Server cron is more reliable where available.

### Social graph

Adds `og:image` and `twitter:image` meta tags (plus `twitter:card` set to `summary_large_image`) to single posts, pages and custom post types, so shared links on Threads, Facebook, X and others show a preview image. It uses the featured image, falling back to the site's **default sharing image** (set per site under **Settings › Reading › Social sharing**), then the site icon; if none exists, no tags are output.

It does nothing on sites running Yoast SEO, Rank Math, All in One SEO, SEOPress or The SEO Framework, which output these tags already. Use the `mst_social_graph_skip` filter to skip it in other cases.

### Design blocks

Adds blocks to the editor on every site, under the **Theme** category in the inserter. Both show a post's featured image as a full-bleed background, under a coloured overlay, with text on top:

- **Featured Excerpt**: the post's excerpt. Made for the top of a post or a single-post template. With no excerpt set, WordPress generates one from the content, shown greyed out in the editor until you write your own.
- **Featured Title**: the post's title as a heading (H2 by default). Made for blog index tiles: put it in the **Post Template** of a Query Loop, e.g. in the Home or Index template in the Site Editor. It links to the post by default.

In the toolbar, set the text's vertical position and alignment (and, for Featured Title, the heading level; on WordPress before 6.4 that's in the sidebar instead). In the sidebar, set the minimum height, the image focal point, the overlay colour and opacity, a text highlight colour, a tilt, and whether the whole block links to the post. **Tilt text** rotates the excerpt or title by an angle between ± the **Maximum tilt** (15° by default), for a scattered look across Query Loop tiles; each post gets its own angle from its ID, so it stays the same on every visit and matches between the editor and the front end. The text highlight puts a bar of colour behind each line of text (e.g. black bars behind white text) to keep it readable over busy images; it can be semi-transparent, and clearing it removes it. They also support wide/full width, text colour, typography (font family, size, weight, style, line height, letter spacing and letter case) and padding. Font families come from the theme: block themes and themes with a `theme.json` font palette offer them; other classic themes show no font family option. A title keeps the theme's heading styles except for the typography settings you change on the block.

Used on the post itself, the text can be typed straight into the block and is saved as the post's excerpt or title; in a Query Loop it's read-only. Without a featured image a block shows the overlay on a dark background, and with neither an image nor text it outputs nothing. Featured Excerpt also outputs nothing on a password-protected post.

The blocks are rendered on the server, so they always show the post's current image and text. Switching the module off leaves the blocks in post content but they output nothing until it's switched back on. Blocks need WordPress 5.8 or later.

To add a block, create `includes/blocks/<name>/` with a `block.json`, `index.js` (plain JS using the `wp.*` globals, since there's no build step), `index.asset.php` listing its script dependencies and `render.php`, then add `<name>` to `MST_Blocks::BLOCKS`. Code shared between blocks lives in `includes/blocks/shared/`: the featured cover's editor script (`window.mstFeaturedCover`), stylesheet and `MST_Blocks::render_cover()`.

### Hide usernames

Closes the common ways a logged-out visitor can find login names:

- `?author=N` returns a 404 instead of redirecting to `/author/<username>/`.
- The REST API `/wp/v2/users` endpoints return 401.
- The `users` sitemap (`wp-sitemap-users-1.xml`) is removed.
- `author-<username>` body classes and `comment-author-<username>` comment classes are dropped (the ID-based `author-N` class stays).
- Failed logins say the username, email or password is incorrect, without saying which.

Logged-in users are unaffected, so the editor's author picker keeps working. Author archive URLs (`/author/<slug>/`) still use each user's nicename, which defaults to their username. There's no screen for changing it, but WP-CLI can: `wp user update <id> --user_nicename=<new-slug>`.

### Hardening switches

Three small protections on the Security & privacy tab. They start **off**; switch on the ones you want.

- **Disable XML-RPC**: `xmlrpc.php` returns 403, and the `X-Pingback` header and RSD link are removed. Bots use XML-RPC to guess passwords and send pingback spam. Don't switch this on if you use Jetpack or an app that publishes over XML-RPC.
- **Disable file editor**: removes the theme and plugin file editors everywhere, the same as `DISALLOW_FILE_EDIT`, so a stolen admin login can't edit code.
- **Hide WordPress version**: removes the generator tag from pages and feeds, and on the front end replaces `?ver=<WordPress version>` on core scripts and styles with a hash of it, so caches still refresh after updates.

## Adding a module

1. Create `includes/modules/class-mst-<slug>.php` with a class that has a `register()` method and static `label()`, `description()` and `category()` methods. `category()` returns one of the keys in `MST_Settings::categories()` (add a new category there if none fits). Optionally add an `enable()` method to reset any state when the module is switched back on, and a static `default_enabled()` returning `false` for a module that should start off.
2. Add `'<slug>' => '<Class_Name>'` to `Multisite_Tools::MODULES` in `includes/class-multisite-tools.php`.
