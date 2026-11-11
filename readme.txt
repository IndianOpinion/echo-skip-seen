=== Echo Skip Seen ===
Contributors: indianopinion
Tags: rss, importer, duplicates, feeds, echo
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Skips feed items whose article the site has already imported, before the importer downloads the page.

== Description ==

Feed importers download every article page before they find out the post already exists, so a busy site fetches the same articles again and again. Echo Skip Seen answers the importer's duplicate filter from a small table of URLs the site has already imported, so a seen article is never downloaded again.

Measured in production: over 22.7 hours, 82.5% of article page downloads were for articles that had already been imported.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

* Works with Echo RSS Post Importer through its public `echo_filter_dup_check` filter.
* Works with any other importer through `echo_skip_seen_is_seen()`, `echo_skip_seen_record()` and matching filter and action.
* Treats the common URL variants of one article as one: mobile and AMP hosts, the Google AMP cache, AMP paths, tracking parameters, fragments, trailing slashes, default ports.
* Stores hashes only, never URLs.
* Fails open: nothing it does can stop an import.
* Tools page, Site Health test and WP-CLI commands.
* Single site, multisite and must-use installs.

The plugin makes no network requests.

== Installation ==

1. Upload the `echo-skip-seen` folder to `wp-content/plugins/` and activate it.
2. Under Tools, Echo Skip Seen, enter the meta key your importer stores the original article URL in. To find it, open one imported post, show Custom Fields, and look for the field with the article URL.
3. Press Run backfill to seed the table from your existing posts, or run `wp skip-seen backfill`.

Must-use install: copy the folder to `wp-content/mu-plugins/echo-skip-seen/` and add `wp-content/mu-plugins/echo-skip-seen-loader.php` containing `require_once __DIR__ . '/echo-skip-seen/echo-skip-seen.php';`.

== Frequently Asked Questions ==

= Does it modify Echo? =

No. It adds one callback to a public filter and listens to WordPress post and meta hooks.

= What happens if the table is missing or broken? =

Every item passes through and the importer behaves as if the plugin were not installed. The Tools page, an admin notice and Site Health show what is missing. `wp skip-seen verify-schema` repairs it.

= Will an article I deleted come back? =

No, by default. A deleted import is usually one an editor rejected. The `echo_skip_seen_delete_on_post_delete` filter changes this.

= What about Google News links? =

They are left alone. A redirect-unwrapping tool can return the publisher URL through the `echo_skip_seen_url` filter.

== Changelog ==

= 0.1.0 =
* First release.
