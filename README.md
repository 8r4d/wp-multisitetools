# Multisite Tools

A toolkit of network admin utilities for WordPress multisite.

## Install

Copy this folder to `wp-content/plugins/multisite-tools/` and **Network Activate** it from Network Admin › Plugins.

## Settings

**Network Admin › Settings › Multisite Multitools** (also linked from the plugin's row on Network Admin › Plugins) lists every module with a checkbox to switch it on or off network-wide. Modules are on by default, including newly added ones, until they're switched off.

## Modules

### Plugin usage

Adds an **Active On** column to Network Admin › Plugins:

- **Network-wide**: network-activated.
- **N sites**: click to expand a list of the sites, each linking to that site's Plugins screen. Archived, spam and deactivated sites are labelled.
- **Not active on any site**: installed but unused, so a candidate for removal.

The plugin-to-site map is cached in a site transient. It's cleared whenever any site's `active_plugins` or `blogname` changes or a site is added, removed or updated, and it expires after 12 hours as a fallback.

### QueueBar

Adds a **N Scheduled** item to the admin toolbar on each site, for users who can edit posts, linking to that site's scheduled posts. It's hidden in Network Admin. It uses the same toolbar ID as the standalone QueueBar plugin, so having both active shows a single item.

It also adds a **Posts by Site** widget to Network Admin › Dashboard: a table of every active site (archived, spam and deactivated sites are left out) with its number of published, scheduled and draft posts, plus a totals row. Each count links to that site's filtered Posts screen. The counts are cached in a site transient that's cleared whenever a post changes status or is deleted on any site, a site's name changes, or a site is added, removed or updated, and it expires after an hour as a fallback.

## Adding a module

1. Create `includes/modules/class-mst-<slug>.php` with a class that has a `register()` method and static `label()` and `description()` methods. Optionally add an `enable()` method to reset any state when the module is switched back on.
2. Add `'<slug>' => '<Class_Name>'` to `Multisite_Tools::MODULES` in `includes/class-multisite-tools.php`.
