# echo-skip-seen

Feed importers download every article page before they find out the post already exists, so a busy site fetches the same articles again and again. This WordPress plugin answers the importer's duplicate filter from a small table of URLs the site has already imported, so a seen article is never downloaded again.

Measured in production: over 22.7 hours, 82.5% of article page downloads were for articles that had already been imported.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

It works with Echo RSS Post Importer through its public `echo_filter_dup_check` filter, and with any other importer through a function, a filter or an action.

## Contents

- [Install](#install)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [How it works](#how-it-works)
- [Public interface](#public-interface)
- [Limitations](#limitations)
- [FAQ](#faq)
- [Development](#development)
- [Licence](#licence)

## Install

Requirements: WordPress 6.4 or later, PHP 8.1 or later, MySQL 5.7+ or MariaDB 10.4+. The PHP `intl` extension is optional (it lets Unicode and punycode host names meet).

### Without Composer (normal plugin)

1. Download the release zip, or `git clone https://github.com/IndianOpinion/echo-skip-seen.git` into `wp-content/plugins/echo-skip-seen`.
2. Activate **Echo Skip Seen** under Plugins. On a network, network-activate it to create the table on every site.

Nothing else is needed at runtime: the plugin ships its own autoloader.

### Without Composer (must-use plugin)

1. Copy the plugin folder to `wp-content/mu-plugins/echo-skip-seen/`.
2. Copy [`examples/mu-plugin-loader.php`](examples/mu-plugin-loader.php) to `wp-content/mu-plugins/echo-skip-seen-loader.php`. It is one line:

   ```php
   require_once __DIR__ . '/echo-skip-seen/echo-skip-seen.php';
   ```

The table is created on the first request on each site (front end, admin, cron or WP-CLI), and the daily prune is scheduled the same way.

### With Composer

```sh
composer require indianopinion/echo-skip-seen
```

With `composer/installers` the package lands in `wp-content/plugins/echo-skip-seen` (its type is `wordpress-plugin`). If you load Composer's autoloader yourself, the plugin uses it and skips its own.

## Quick start

1. Find the meta key your importer stores the original article URL under. Open one imported post in the editor, show **Custom Fields**, and look for the field that holds the article URL.
2. Go to **Tools, Echo Skip Seen**, put that key in **Source URL meta keys**, and save.
3. Press **Run backfill** to seed the table from the posts you already have. Or from the shell:

   ```sh
   wp skip-seen backfill --meta-key=<your_key> --dry-run
   wp skip-seen backfill --meta-key=<your_key>
   ```

4. Check one URL you know is imported:

   ```sh
   wp skip-seen check 'https://m.example.com/news/amp/story-1?utm_source=rss'
   ```

   `lookup would skip: yes` means the next import run will not download it.
5. Watch the counters on the Tools page or with `wp skip-seen stats`. The skip rate is the share of item URLs the importer did not have to download.

From then on, every post an importer creates with that meta key is recorded automatically.

## Configuration

Settings live on **Tools, Echo Skip Seen** (one option per site, not autoloaded). Every default can also be changed with a filter.

| Setting | Default | How to change | Notes |
|---|---|---|---|
| Enabled | on | Tools page, `echo_skip_seen_enabled` filter, `ECHO_SKIP_SEEN_DISABLED` constant | The constant wins. Off means every value passes through. |
| Source URL meta keys | empty | Tools page, `echo_skip_seen_source_meta_keys` filter, `wp skip-seen backfill --meta-key=` | Ordered; the first value that is an http or https URL wins. Empty means only direct calls record. |
| Retention | 90 days | Tools page, `echo_skip_seen_retention_days` filter | Days after last seen. 0 keeps rows forever. A hit refreshes last seen. |
| Post types | `post` | `echo_skip_seen_post_types` filter | Which post types count as imported. |
| Delete rows on post delete | off | `echo_skip_seen_delete_on_post_delete` filter | Off: a deleted import should not come back. |
| Debug logging | off | Tools page | One PHP error log line per skip and per failure. |
| Echo callback priority | 10 | `ECHO_SKIP_SEEN_PRIORITY` constant, `echo_skip_seen_priority` filter (add it from a must-use plugin) | |
| Lower-case paths | off | `echo_skip_seen_lowercase_path` filter, per host | Opt a host in when it routes paths case-insensitively. |
| Wrapper hosts | `news.google.com` | `echo_skip_seen_wrapper_hosts` filter | URLs on these hosts are left to an unwrap tool. |
| Mirror host labels | `www`, `www1`, `www2`, `www3`, `ww`, `m`, `mobile`, `amp`, `wap`, `mob`, `touch`, `lite` | `echo_skip_seen_mirror_labels` filter | Leading labels removed while at least two remain. |
| Tracking parameters | `utm_*` and a list of 38 names | `echo_skip_seen_tracking_params` filter | Always dropped. |
| Id parameters | `p`, `id`, `story`, `article`, `newsid` and 15 more | `echo_skip_seen_id_params` filter | The only parameters kept on slug paths. |
| Script extensions | `asp`, `aspx`, `php`, `jsp`, `cfm`, `cgi`, `do` | `echo_skip_seen_script_extensions` filter | On these paths, and on `/`, every non-tracking parameter is kept. |
| Article id rules | none | `echo_skip_seen_article_id_rules` filter | Host to regular expression; the `id` group (or group 1) becomes the key. |

See [`examples/configure.php`](examples/configure.php) for each filter in use.

## How it works

```
feed item URL
     |
     v
echo_filter_dup_check ---> echo-skip-seen
                              |  not a string / not http(s) / wrapper host / own host
                              |------------------------------------------------> value unchanged
                              |  canonical key + plain key -> two MD5 hashes
                              |  one indexed query (cached per request)
                              |------ match, not expired -----------------------> true (Echo skips)
                              |------ miss or any failure ----------------------> value unchanged

post created with source URL meta  --->  record: write both hashes (INSERT IGNORE)
```

- **Seen means imported.** Rows are written only when a post exists: on `wp_after_insert_post`, on the source meta being added or updated (importers often write it after the insert), or when an importer calls the record function or action. A lookup never records.
- **Two keys per URL.** The canonical key collapses the usual variants of one article: scheme, `www.` and `m.` and `amp.` hosts, AMP paths and the Google AMP cache, tracking parameters, fragments, trailing slashes, default ports, `index.html`, dot segments, percent-encoding case. On a slug path only id parameters survive; on a script endpoint such as `/?p=1` or `/page.aspx?id=7` the query is kept, because there it names the page. The plain key is a frozen, conservative exact-match key that keeps matching when the canonical rules change.
- **Hashes only.** The table stores a 16-byte MD5 per key, the key kind and version, the post id and two UTC times. No URL is stored.
- **Fail open.** A missing table, a database error, a broken filter callback or a URL that cannot be keyed all mean "not seen": Echo gets its value back and downloads as usual. After one database error in a request the plugin stops querying for the rest of that request.
- **Cheap.** Hooks register without a query. Counters are flushed in one option write at shutdown. A hit refreshes last seen at most once an hour per row.
- **Schema checked.** After creating or upgrading the table the plugin reads the real columns and indexes back, repairs anything missing with `ALTER TABLE`, and only then stores the schema version.

The full design, with every decision, is in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md). The requirements are in [docs/PRD.md](docs/PRD.md).

## Public interface

Functions (global):

```php
echo_skip_seen_is_seen( string $url ): bool          // false on any failure, never throws
echo_skip_seen_record( string $url, int $post_id = 0 ): bool  // true when a new row was written
echo_skip_seen_keys( string $url ): array            // both keys and hashes, [] for a non-URL
```

Actions: `echo_skip_seen_record_url( $url, $post_id )` to record, and `echo_skip_seen_skipped( $url, $post_id, $kind )`, `echo_skip_seen_recorded( $url, $post_id, $rows )`, `echo_skip_seen_failed_open( $where, $reason )` to observe.

Filters, besides those in the configuration table: `echo_skip_seen_is_seen( false, $url )` (ask through a filter), `echo_skip_seen_url( $url, $context )` (pre-process, for example to unwrap a redirect), `echo_skip_seen_host( $host, $url )`, `echo_skip_seen_canonical_key( $key, $url )`, `echo_skip_seen_amp_segment_names`, `echo_skip_seen_should_record( $yes, $post, $url )` and `echo_skip_seen_counters`.

WP-CLI:

```
wp skip-seen backfill [--batch=<n>] [--from-id=<id>] [--since=<date>] [--post-type=<types>] [--meta-key=<keys>] [--include-expired] [--dry-run]
wp skip-seen check <url> [--format=table|json]
wp skip-seen record <url> [--post-id=<id>]
wp skip-seen stats [--format=table|json] [--reset]
wp skip-seen prune
wp skip-seen verify-schema
```

Examples: [another importer](examples/custom-importer.php), [redirect unwrapping](examples/unwrap-redirects.php), [logging](examples/log-skips.php), [keys without WordPress](examples/keys-cli.php).

## Limitations

- It only knows URLs. Two different URLs for one article (a syndicated copy, a new slug) are two articles unless an article id rule or the host filter joins them.
- Google News and other redirect wrappers are left alone. Without an unwrap tool on the `echo_skip_seen_url` filter, those items are downloaded as before.
- It knows only what was imported after install or backfill. Run the backfill once.
- It needs the source URL meta key. With none configured, only direct calls record.
- Without `intl`, a Unicode host and its punycode form give different keys.
- Paths are case-sensitive by default. A publisher that serves `/A/Story` and `/a/story` as one page needs the lower-case filter for its host.
- When a parameter repeats, the last value wins, as PHP reads a query string.
- Counters are an operating aid: a request that dies before shutdown loses its counts.

## FAQ

**Does it change Echo or any other plugin?** No. It adds one callback to a public filter and listens to WordPress post and meta hooks.

**What happens if the table breaks?** Every lookup passes through and the importer runs as if the plugin were not installed. The Tools page, an admin notice and Site Health say what is missing; `wp skip-seen verify-schema` repairs it.

**Will it skip an article I deleted?** Yes, by default, and that is deliberate: a deleted import is usually one the editor rejected. Turn on `echo_skip_seen_delete_on_post_delete` to forget a post's rows when it is deleted permanently.

**Why not store the URL?** Hashes keep the table small (about 60 bytes a row) and hold nothing beyond "some post came from some URL". `wp skip-seen check <url>` recomputes the keys whenever you need to debug.

**What if a feed item is checked but the import then fails?** Nothing is recorded, so the next run tries again. Only a created post is recorded.

**Does it work with a page cache or CDN?** It does nothing on page views, so they do not matter.

**Can I use it without Echo?** Yes. Call `echo_skip_seen_is_seen()` before you fetch and `echo_skip_seen_record()` after you create the post, or use the filter and action of the same names.

**How do I remove it?** Deactivate to stop it and keep the data. Delete it from the Plugins screen to drop the table and every option, on every site of a network.

## Development

See [CONTRIBUTING.md](CONTRIBUTING.md). In short:

```sh
composer install
composer check   # phpcs, phpstan, phpunit
```

## Licence

MIT. See [LICENSE](LICENSE).
