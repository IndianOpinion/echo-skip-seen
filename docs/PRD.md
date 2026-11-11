# echo-skip-seen: product requirements

Status: draft for review. Audience: the builder, the code reviewer and anyone
deciding whether to install the plugin. This document describes behaviour,
not code. Where it says "must", the acceptance tests in section 12 check it.

## 1. Problem

Feed importers for WordPress fetch the article page for every feed item, and
only then decide whether the item is a duplicate of a post that already
exists. A feed lists the same article for hours or days, and a site that
follows many feeds sees the same article through several of them. Every run
therefore downloads pages it will throw away. That costs bandwidth, PHP time,
importer run time, and goodwill with publishers whose servers see the same
fetch again and again (some rate-limit or block the fetching host).

Echo RSS Post Importer offers a public filter, `echo_filter_dup_check`, that it
applies to each item's URL before it downloads the page. When a callback
returns true, Echo skips the item. Nothing in WordPress answers that filter by
default, so the hook goes unused.

Measured result in production: in a 22.7 hour window, 82.5% of the article
page downloads (Google News wrapper links excluded) were articles that had
already been imported and were thrown away after the download.

echo-skip-seen answers that filter from a small table of URLs the site has
already imported, so a seen article is never downloaded again.

## 2. Goals and non-goals

Goals:

1. Answer `echo_filter_dup_check` with true for an item URL whose article has
   already been imported, before any download happens.
2. Keep its own small table of seen URL keys, independent of the importer's
   own data and of the posts table schema.
3. Treat the common URL variants of one article as one article: mobile and
   AMP hosts, AMP paths, tracking parameters, fragments, trailing slashes,
   scheme, default ports, host case and percent-encoding case.
4. Record a URL as seen when an importer creates a post, without any change
   to the importer.
5. Seed the table from existing posts (backfill) through WP-CLI and through an
   admin button.
6. Expire old rows after a configurable retention.
7. Expose counters (checked, skipped, recorded, failed open) on a small admin
   page and through a filter.
8. Fail open: no error inside the plugin may ever stop an import.
9. Work for any importer that can call a public function or apply a filter
   with a URL. Echo is the first integration, not the only one.
10. Run as a normal plugin or as a must-use plugin.

Non-goals:

1. Title-based duplicate detection. Two articles with the same headline are
   not the same URL; that problem belongs to another tool.
2. Content fingerprinting or near-duplicate detection (simhash, shingles,
   embeddings).
3. Resolving redirect wrappers such as Google News interstitial links. That
   needs a network call or a decoder and is left to a separate tool, which can
   plug in through the URL filter in section 5.
4. Editing, patching or wrapping Echo or any other importer. The plugin only
   uses public hooks.
5. Fetching anything over the network. The plugin never makes an HTTP request.
6. Deciding editorial policy. A skipped item is one the site already has; the
   plugin does not judge whether a post should exist.
7. Site-specific article-id or headline-slug rules. The plugin ships none; a
   filter lets a site add its own (section 5).

## 3. Users and setting

- Who installs it: the operator of a WordPress site that imports posts from
  RSS or Atom feeds with Echo RSS Post Importer or a similar importer. Often a
  single person running a news aggregation or curation site.
- Where it runs: inside WordPress, on the same request or cron run that
  executes the importer. Imports usually run from WP-Cron or from a system
  cron calling WP-CLI, so most calls happen with no logged-in user and no
  admin screen.
- How it is installed: as a normal plugin from a zip or Git checkout, or as a
  must-use plugin by copying the plugin into `wp-content/mu-plugins/` with a
  one-line loader file. No Composer at runtime.
- Who reads the admin page: the same operator, occasionally, to check that the
  plugin is working and to run a backfill once after install.

## 4. Behaviour

### 4.1 Definitions

- **Item URL**: the URL an importer is about to fetch for one feed item.
- **Source URL**: the original article URL stored with an imported post (in
  post meta, by the importer).
- **Seen**: a URL whose article key is in the table because a post was
  created from it. Seen means imported, never merely fetched or checked.
- **Canonical key**: the normalised article identity described in 4.2.
- **Plain key**: a conservative exact-match key described in 4.3.
- **Key hash**: a fixed-length hash of a key, stored in the table. The hash is
  for compact equality lookup, not for security.

Each recorded URL produces two rows, one per key kind. A lookup computes both
keys of the item URL and matches if either hash is present, whatever kind
the stored row has.

### 4.2 Canonical key: normalisation cases

The canonical key is built from the host, the path and a filtered query. The
scheme is not part of the key. Each case below is a separate requirement.

Input cleaning:

1. Leading and trailing whitespace is trimmed.
2. HTML entities are decoded once (`&amp;` becomes `&`), because feeds often
   carry encoded URLs. Decoding happens exactly once; a doubly encoded
   `&amp;amp;` decodes to `&amp;`, not to `&`.
3. A value that does not start with `http://` or `https://`
   (case-insensitive) is not a URL for this plugin. The lookup passes it
   through unchanged (4.6); the record path ignores it (4.5).
4. A URL that does not parse, or parses without a host, yields no key. Lookup
   passes through; record ignores it.
5. User information (`user:pass@`) is dropped from the key and never stored.

Scheme and port:

6. `http` and `https` give the same key.
7. A default port (`:80` with http, `:443` with https) is dropped. Because
   the scheme is not in the key, `:80` and `:443` are dropped whichever
   scheme they appear with.
8. A non-default port is kept in the key (`example.com:8080/a` differs from
   `example.com/a`).

Host:

9. The host is lower-cased.
10. A trailing dot on the host (`example.com.`) is removed.
11. An internationalised host is converted to its ASCII (punycode) form when
    the PHP `intl` extension is available, so the Unicode and punycode
    spellings meet. Without `intl` the Unicode host is lower-cased and kept as
    is, and the two spellings do not meet (documented limitation; see 4.10).
12. Leading delivery-variant labels are removed one at a time while at least
    two labels remain: `www`, `www1`, `www2`, `www3`, `ww`, `m`, `mobile`,
    `amp`, `wap`, `mob`, `touch`, `lite`. So `m.example.com`,
    `amp.example.com`, `www.m.example.com` and `example.com` give the same
    host part. The list is filterable.
13. A bare two-label host is never reduced further (`m.com` stays `m.com`).
14. An IP address host is kept as is (no label stripping).
15. Google AMP cache hosts (any host ending in `.cdn.ampproject.org`) are
    unwrapped: the path form `/c/s/<publisher host>/<path>` (and `/c/`,
    `/v/`, `/i/` variants, with or without the `s/` secure marker) becomes
    `https://<publisher host>/<path>` with the original query, and the
    canonical key is computed for that URL instead. Unwrapping happens once;
    a nested AMP cache URL is not unwrapped again.
16. Language or regional subdomains (`hindi.example.com`,
    `en.example.com`) are NOT merged with the parent host by default. An
    edition in another language is a different article. Sites that want them
    merged use the host filter.

Path:

17. An empty path is `/`.
18. Percent-encoding is normalised the RFC 3986 way: hex digits are
    upper-cased, and only unreserved characters (letters, digits, `-`, `.`,
    `_`, `~`) are decoded. Reserved characters such as `%2F` stay encoded, so
    `/a%2Fb` and `/a/b` stay different. `%7E` and `~` meet; `%e2%82%b9` and
    `%E2%82%B9` meet.
19. A path written with raw non-ASCII characters and the same path
    percent-encoded as UTF-8 give the same key.
20. The path is lower-cased. Most news sites route case-insensitively and
    feeds mix case; a site whose paths are case-sensitive turns this off
    with a filter (the plain key in 4.3 still matches exact repeats).
21. Repeated slashes collapse to one (`/a//b` becomes `/a/b`).
22. A trailing slash is removed, except for the root path `/`.
23. A trailing `index.html`, `index.htm` or `index.php` segment is removed.
24. AMP path variants collapse to the plain article path:
    a. a leading `/amp/` segment (`/amp/story` becomes `/story`);
    b. a trailing `/amp` segment (`/story/amp` becomes `/story`);
    c. an inner `/amp/` segment (`/news/amp/story` becomes `/news/story`);
    d. a trailing `/amp/1` segment pair;
    e. an `.amp` suffix (`story.amp` becomes `story`);
    f. `.amp.html` and `-amp.html` endings become `.html`;
    g. an `amp_` prefix on a known article segment name is removed (an
       `amp_articleshow` segment becomes `articleshow`), from a filterable
       list that ships with that one entry.
    A path segment that merely contains the letters "amp" (`/camp/`,
    `/example-amplifier`) is never touched.
25. Dot segments (`/a/./b`, `/a/../b`) are resolved before the other path
    rules.

Query:

26. Parameter names are compared lower-cased.
27. Tracking parameters are always dropped, whatever the path: every name
    starting with `utm_`, plus a filterable list that ships with at least
    `fbclid`, `gclid`, `dclid`, `msclkid`, `igshid`, `mc_cid`, `mc_eid`,
    `_gl`, `ref`, `ref_src`, `referrer`, `source`, `src`, `from`, `cmp`,
    `cmpid`, `intcmp`, `ocid`, `ncid`, `s_cid`, `smid`, `spm`, `share`,
    `shared`, `fromapp`, `pfrom`, `ito`, `tbref`, `sr_share`, `at_medium`,
    `at_campaign`, `rss`, `rssfeed`, `feed`, `feedtype`, `outputtype`, `amp`,
    and `__twitter_impression`.
28. Parameters with an empty value are dropped.
29. Array parameters (`a[]=1&a[]=2`) are dropped from the canonical key (they
    almost never identify an article; the plain key still sees them).
30. On a slug path (any path that is not a script endpoint, see 31), only
    id-bearing parameters survive. The filterable id list ships with `p`,
    `id`, `story`, `storyid`, `story_id`, `article`, `articleid`,
    `article_id`, `newsid`, `news_id`, `nid`, `sid`, `aid`, `pid`, `prid`,
    `contentid`, `content_id`, `postid`, `post_id` and `v`. So
    `/2026/11/story?page=2&foo=bar` keys as `/2026/11/story`, but
    `/news?id=42` and `/news?id=43` stay different.
31. On a script endpoint, every non-tracking parameter survives, because the
    query names the page. A script endpoint is the root path `/` or a path
    ending in `.asp`, `.aspx`, `.php`, `.jsp`, `.cfm`, `.cgi` or `.do`. So
    `/?p=1` and `/?p=2` differ, and `/page.aspx?PRID=7&lang=1` keeps both.
32. Surviving parameters are sorted by name, so parameter order never
    matters (`?b=2&a=1` and `?a=1&b=2` meet).
33. Parameter values keep their case. Names are lower-cased (26).
34. When a name repeats (`?id=1&id=2`), the last value wins, matching how PHP
    reads a query string. Documented, not configurable.
35. If no parameter survives, the key has no `?`.

Fragment:

36. The fragment (`#...`) is always dropped.

Extensibility:

37. A site may register article-id rules per host: a pattern that extracts a
    stable id from the path, so one article filed under two section paths or
    two slugs gives one key (host plus id). The plugin ships no rules.
38. A final filter receives the computed canonical key and the original URL
    and may return a different key. An empty return falls back to the
    computed key.
39. The canonical key is deterministic: the same input gives the same key in
    every PHP version, locale and request.
40. Every key carries a key version number in the table (6.2). Changing any
    default rule above is a key version change (see 4.11 and 14).

### 4.3 Plain key

41. The plain key is the URL with: whitespace trimmed, entities decoded once
    (as in 2), the scheme and `://` removed, one leading `www.` removed, the
    fragment removed, one trailing slash removed from the path, and the whole
    string lower-cased. The query is kept as written, in its original order.
42. The plain key exists to catch exact repeats that the canonical rules
    might miss after a rule change, and to give a safety net if a site's
    filters make the canonical key too narrow. It is deliberately simple and
    must never be changed once released (it is key version independent).
43. Note on scope: the plain key keeps the query on purpose. A host-and-path
    key without the query would merge `/?p=1` with `/?p=2` and wrongly skip
    real articles.

### 4.4 URL pre-processing hook

44. Before either key is computed, on both the record and the lookup path,
    the URL passes through a public filter. A redirect-unwrapping tool can
    return the publisher URL there. The plugin itself adds no callback.
45. If the filter returns anything other than a non-empty string, the
    original URL is used.
46. Google News interstitial links (host `news.google.com` with a path
    containing `/articles/` or `/rss/articles/`) are left to such a tool:
    when the pre-processing filter returns them unchanged, the lookup passes
    through without a query and the record path does not store them. They
    must never cause an error, a skip, or a stored row. The host list for
    "wrapper URLs left alone" is filterable.

### 4.5 Record path

47. The plugin hooks post insertion (after the post exists, so it has an id).
48. A filter decides whether a post counts as imported. The default answer is
    yes when the post has a source URL (49), its post type is in a
    filterable list (default `post`), and its status is not `auto-draft`.
    Drafts, pending posts and scheduled posts count, because importers often
    create posts unpublished.
49. The source URL is read from post meta. Which meta keys hold it is
    configuration: a filterable, ordered list of meta keys, also editable on
    the settings page. The first key whose value is a valid http or https URL
    wins. The default list is empty; see 7 and question 1 in section 13.
50. Importers often write meta after the post insert. The plugin therefore
    also listens for add and update of any configured source meta key, and
    records the post then. Whichever event comes first records; the second
    is a no-op.
51. An importer that does not use post meta records directly with the public
    record function (URL plus optional post id) or the record action.
52. A URL on the site's own host (the home URL host, after the same host
    normalisation) is never recorded. Fallbacks such as the post guid or
    permalink point at the site itself and would poison the table.
53. Recording writes two rows (canonical and plain), each with the key hash,
    the key kind, the key version, the post id (0 when unknown) and the time.
    When both keys hash to the same value, one row is written.
54. Recording is idempotent. A key hash that is already present is not an
    error. The existing row keeps its original post id; its last-seen time is
    refreshed.
55. Recording the same URL from two posts (two feeds imported it before the
    table knew it) keeps the first post id and counts as recorded once per
    new row actually written.
56. A failure while recording (table missing, write error, exception) is
    caught, counted as failed open, and never interrupts the post insert or
    the importer.
57. Revisions, autosaves and post types outside the configured list are never
    recorded.
58. Deleting or trashing a post does not remove its rows by default. A
    trashed import is usually a post the editor rejected, and the importer
    should not bring it back. A filter can switch to removing rows when a
    post is deleted permanently.
59. Updating a post whose source URL meta changes records the new URL; the
    old rows stay until retention removes them.
60. Recording happens only on the record path. A lookup never records, even
    on a miss, because an item that was checked may still fail to import.

### 4.6 Lookup path

61. The plugin adds one callback to `echo_filter_dup_check` at a default
    priority (filterable through a constant or an early filter) with one
    argument.
62. If the incoming value is not a non-empty string, the callback returns it
    unchanged. This covers another callback that already answered true, and
    any non-URL value the filter may carry.
63. If the incoming string is not an http or https URL (case 3), it is
    returned unchanged.
64. If the plugin is disabled (setting, constant, or filter), the value is
    returned unchanged and nothing is counted as checked.
65. The URL goes through the pre-processing filter (44). A wrapper URL left
    unchanged (46) is returned unchanged with no query.
66. A URL on the site's own host is returned unchanged.
67. Both keys are computed and hashed, and one indexed query asks whether
    either hash exists (any kind, any key version that is still accepted,
    see 4.11). Rows past the retention cutoff are treated as absent even if
    the cleanup has not yet run.
68. On a match the callback returns boolean true, the skipped counter goes
    up, the matched row's last-seen time is refreshed (rate-limited so one
    row is written at most once per hour), and an action fires with the URL,
    the matched post id and the key kind that matched.
69. On no match the callback returns the incoming value unchanged. It never
    returns a URL that differs from the one it received.
70. Within one request, results are cached in memory per item URL, so a URL
    checked twice in one run costs one query. A record in the same request
    updates that cache.
71. A public function answers the same question for any importer: given a
    URL, it returns true when seen and false otherwise (including on any
    error). A matching public filter (default false) lets importers that
    prefer filters ask the same question.

### 4.7 Fail-open rule

72. Every lookup failure returns the incoming value unchanged (for the Echo
    callback) or false (for the public function and filter). Failures
    include: table missing, schema check failed, database error, an
    exception or PHP error from a third-party filter callback, `intl`
    missing, a malformed URL, and a key longer than the plugin accepts.
73. Each failure increments the failed-open counter and, when debug logging
    is on, writes one log line with the reason (never the full query).
74. Fail open is the only mode. There is no fail-closed setting, because a
    wrong skip loses an article silently while a wrong pass costs one
    download that the importer's own duplicate check still catches.
75. The plugin never uses the PHP error suppression operator. Errors are
    caught as exceptions and `Throwable`, and database errors are read from
    the database layer's last error after each query.
76. If the database layer reports an error on the lookup query, the plugin
    stops issuing lookups for the rest of the request (a circuit breaker), so
    a broken table does not add one failing query per feed item.

### 4.8 Backfill

77. Backfill seeds the table from existing posts' source URLs, using the same
    meta key list and the same "counts as imported" filter as the record
    path (status `auto-draft` aside, trashed posts are included, see 58).
78. WP-CLI command with: batch size (default 500), a starting post id for
    resuming, an optional post-date floor, post types, `--dry-run` (counts
    and reports, writes nothing), and a progress bar. It prints posts
    scanned, posts with a source URL, rows written, rows already present and
    URLs rejected, then exits 0. It exits non-zero only when the table cannot
    be created or verified.
79. Admin button on the plugin page runs the same backfill in batches, one
    batch per AJAX request, with a nonce and a capability check, showing
    progress and the same totals. Closing the page stops it; pressing the
    button again resumes from the stored cursor.
80. Backfill walks posts in ascending id order by keyset (id greater than the
    last id), never by offset, so it scales to millions of posts.
81. Backfill is idempotent: running it twice writes no new rows the second
    time.
82. Backfill skips posts older than the retention window (it would write
    rows that cleanup deletes at once) unless asked to include them.
83. Backfill sets each row's first-seen time to the post's date (GMT), so
    retention treats old posts as old. The last-seen time is the same.

### 4.9 Retention

84. Rows expire when their last-seen time is older than the retention period.
    Default 90 days; configurable in days; 0 means keep forever.
85. Expired rows are deleted by a daily scheduled event in batches (default
    5,000 rows per statement, looping until done or until a time budget of a
    few seconds is spent, then continuing next run).
86. Retention is checked at lookup time too (67), so a missed cron run does
    not lengthen retention in effect.
87. A refresh of last-seen on a hit (68) keeps a URL that a feed still lists
    from expiring while it is still being offered.
88. The scheduled event is removed on deactivation and re-added on
    activation. Under a must-use install it is (re)scheduled on `init` when
    missing.

### 4.10 Multisite

89. Each site has its own table, named with that site's table prefix.
90. Network activation creates the table on every existing site, in batches
    for large networks; creating a new site creates its table; deleting a
    site drops its table.
91. Counters, settings and the backfill cursor are per site.
92. A must-use install on multisite creates a site's table lazily on first
    use on that site (after the schema check, see 4.11).
93. Imports into site A never answer lookups on site B.

### 4.11 Table creation and upgrade

94. The table is created on activation, and for must-use installs on the
    first admin or CLI request where the stored schema version is missing or
    older than the code's.
95. The stored schema version is updated only after the schema has been
    verified (96), never right after the create or alter statement.
96. After any create or upgrade, the plugin reads the actual column list and
    index list from the database and compares them with what the code
    expects. If a column or index is missing, it tries an explicit `ALTER
    TABLE` for that column or index, then verifies again.
97. If verification still fails, the plugin records the failure, shows an
    admin notice naming the missing column or index, and every lookup and
    record fails open (72) until the schema is fixed. The importer keeps
    working as if the plugin were not there.
98. Lookups do not run the schema check on every request. They trust the
    stored schema version and rely on the circuit breaker (76) if the table
    is broken anyway.
99. A key version change (40) does not rewrite old rows. Lookups accept the
    current version and the previous one for the length of one retention
    period, then old rows age out. The plain key does not change across
    versions, so exact repeats keep matching throughout.
100. Uninstall (not deactivation) drops the table, deletes the plugin's
     options and scheduled events, on every site of a network.

### 4.12 Counters

101. Counters: checked (lookups that reached the database or cache), skipped
     (lookups that matched), recorded (new rows written), and failed open.
     Each also has a "since" timestamp.
102. Counters are accumulated in memory and flushed once per request at
     shutdown, in one option write, so a run of 500 items costs one write,
     not 500.
103. A lost flush (fatal error, timeout) loses at most one request's counts.
     Counters are an operating aid, not an audit log.
104. Counters can be reset from the admin page (nonce, capability) and from
     WP-CLI.
105. A public filter returns the counters as an array, and a WP-CLI command
     prints them (also as JSON).

## 5. Public interface

Names below are the public contract; the builder chooses the internal
structure. All hook names share the prefix `echo_skip_seen_`, all PHP symbols
live under the namespace `IndianOpinion\EchoSkipSeen`, and a handful of
global convenience functions use the prefix `echo_skip_seen_`.

Functions (global, stable):

- `echo_skip_seen_is_seen( string $url ): bool`: the lookup (71). Never
  throws.
- `echo_skip_seen_record( string $url, int $post_id = 0 ): bool`: records a
  URL; returns true when at least one new row was written. Never throws.
- `echo_skip_seen_keys( string $url ): array`: returns the canonical key, the
  plain key and their hashes, for debugging and for other tools. Empty array
  for a non-URL.

Filters:

- `echo_filter_dup_check` (Echo's): the integration point (4.6).
- `echo_skip_seen_is_seen` (bool $seen, string $url): for importers that ask
  through a filter (71).
- `echo_skip_seen_enabled` (bool): master switch; default from the setting.
- `echo_skip_seen_url` (string $url, string $context): pre-processing (44);
  context is `lookup` or `record`.
- `echo_skip_seen_wrapper_hosts` (string[]): hosts left to an unwrap tool
  (46); default `news.google.com`.
- `echo_skip_seen_mirror_labels` (string[]): host labels stripped (12).
- `echo_skip_seen_host` (string $host, string $url): final host part of the
  canonical key (16).
- `echo_skip_seen_lowercase_path` (bool, string $host): case 20.
- `echo_skip_seen_tracking_params` (string[]): case 27.
- `echo_skip_seen_id_params` (string[]): case 30.
- `echo_skip_seen_script_extensions` (string[]): case 31.
- `echo_skip_seen_amp_segment_names` (string[]): case 24g.
- `echo_skip_seen_article_id_rules` (array host to pattern): case 37.
- `echo_skip_seen_canonical_key` (string $key, string $url): case 38.
- `echo_skip_seen_source_meta_keys` (string[]): case 49.
- `echo_skip_seen_should_record` (bool, WP_Post $post, string $url): case 48.
- `echo_skip_seen_post_types` (string[]): default `post`.
- `echo_skip_seen_retention_days` (int): case 84.
- `echo_skip_seen_delete_on_post_delete` (bool): case 58; default false.
- `echo_skip_seen_counters` (array): read-only counters (105).

Actions:

- `echo_skip_seen_record_url` (string $url, int $post_id): record without
  calling the function.
- `echo_skip_seen_skipped` (string $url, int $post_id, string $kind): after
  a match (68).
- `echo_skip_seen_recorded` (string $url, int $post_id, int $rows).
- `echo_skip_seen_failed_open` (string $where, string $reason).

WP-CLI (`wp skip-seen ...`):

- `backfill [--batch=<n>] [--from-id=<id>] [--since=<date>]
  [--post-type=<types>] [--include-expired] [--dry-run]`
- `check <url>`: prints both keys, both hashes, and whether each is present.
- `record <url> [--post-id=<id>]`
- `stats [--format=table|json]` and `stats --reset`
- `prune`: runs retention now.
- `verify-schema`: runs the check in 96 and repairs if it can; exit code
  non-zero on failure.

Constants (optional, in `wp-config.php`):

- `ECHO_SKIP_SEEN_DISABLED`: true turns the plugin off without deactivating
  it (overrides the setting).
- `ECHO_SKIP_SEEN_PRIORITY`: priority of the Echo callback (default 10).

Settings (one option array, not autoloaded): enabled (default on), retention
days (90), source meta keys (empty list), debug logging (off). Settings page
under Tools, capability `manage_options`.

## 6. Data

### 6.1 What is stored

One table per site, `{prefix}echo_skip_seen`. Per row:

- key hash: 16-byte binary (MD5 of the key). Unique index.
- key kind: canonical or plain (one small integer).
- key version: small integer (canonical key rule set; 0 for plain).
- post id: unsigned big integer, 0 when unknown. Plain index for the
  delete-on-post-delete path.
- first seen and last seen: UTC datetimes. Index on last seen for retention.

The URL itself is not stored. Storing hashes only keeps the table small (about
60 bytes a row plus indexes) and stores nothing a reader of the table could
use beyond "this post came from some URL". `wp skip-seen check` recomputes
keys from a URL when an operator needs to debug.

Options: settings (6.1 above), counters, schema version, backfill cursor,
last schema check result. None autoloaded except a small "enabled and schema
ok" flag if the builder needs it on the hot path.

### 6.2 Size

Two rows per imported article. A site importing 2,000 articles a day with 90
days retention holds about 360,000 rows, roughly 30 MB with indexes.

### 6.3 Retention

Section 4.9. Default 90 days after last seen.

### 6.4 Uninstall

Section 4.11 case 100: table dropped, options and events deleted, on every
site. Deactivation keeps everything except the scheduled event.

## 7. Configuration and sensible defaults

| Setting | Default | Why |
|---|---|---|
| Enabled | on | The plugin exists to do this. |
| Retention | 90 days | Feeds rarely relist an article after weeks; a hit refreshes. |
| Source meta keys | empty | The plugin does not describe any importer's internals. The settings page explains how to find the key (look at the custom fields of one imported post) and warns when it is empty that only direct calls and the record action will populate the table. |
| Post types | `post` | What feed importers create by default. |
| Delete rows on post delete | off | A deleted import should not come back. |
| Debug logging | off | Avoid log noise on busy imports. |
| Echo callback priority | 10 | Standard. |
| Backfill batch | 500 posts | Fits a 30 second request on modest hosts. |
| Prune batch | 5,000 rows | Short locks on InnoDB. |

## 8. Security and privacy

- Admin page, settings, backfill button and counter reset require
  `manage_options` and a nonce. AJAX handlers check both before doing
  anything.
- Every value from the request is validated: retention is a non-negative
  integer with a sane upper bound, meta keys match the characters WordPress
  allows in meta keys, batch sizes are clamped.
- Every output in admin is escaped.
- All SQL uses prepared statements. The table name is built from the
  WordPress prefix and a fixed suffix only.
- The plugin makes no network requests and runs no shell commands.
- The table holds hashes, post ids and times, no URLs and no personal data.
  Debug log lines may contain feed URLs; they never contain user data.
- Uninstall removes everything the plugin created.

## 9. Performance budget

- Lookup: at most one indexed query per distinct item URL per request, with
  key computation under 0.2 ms; query under 2 ms on a table of one million
  rows on commodity MySQL or MariaDB.
- No writes on a miss. On a hit, at most one row update per row per hour.
- Record: one insert statement for both rows (insert ignoring duplicates,
  then a last-seen update only when the row already existed).
- Counters: one option write per request at shutdown.
- No query and no option read on front-end page views that do not import.
  Hooks register cheaply; settings load only when a callback runs.
- Prune: batched deletes, a few seconds per daily run at most.
- Memory: the per-request cache is capped (default 5,000 URLs, least recent
  dropped).

## 10. Compatibility

- PHP 8.1, 8.2, 8.3. `intl` optional (case 11).
- WordPress 6.4 or later, single site and multisite.
- MySQL 5.7+ and MariaDB 10.4+. InnoDB.
- Normal plugin or must-use plugin (loader file documented in README).
- Echo RSS Post Importer: any version that applies `echo_filter_dup_check`
  before downloading. Other importers: through the public function, filter or
  action.
- Persistent object caches: not required. The plugin does not cache lookups
  across requests (the table query is cheap, and a stale cache would delay a
  record).
- Page caches and CDNs: irrelevant; the plugin does nothing on page views.
- Runs under WP-Cron, system cron with WP-CLI, and admin-triggered imports.

## 11. Observability

- Admin page under Tools: enabled state, schema state (ok, or the missing
  column or index), row count, oldest and newest last-seen, counters with
  their "since" time, skip rate (skipped over checked), retention, the meta
  key list, the backfill button and progress, and a "check a URL" box that
  shows both keys and whether each is present.
- WP-CLI `stats`, `check` and `verify-schema` (section 5).
- Action hooks on skip, record and failed open, so an operator can log every
  skip to their importer's log or their own.
- Debug logging (off by default): one line per failed open and per skip
  through the PHP error log.
- Site Health: one test that reports a failed schema check or an empty meta
  key list with no recorded rows after a day.

## 12. Acceptance tests

Each test names the cases it covers. Tests run under PHPUnit with a stub
layer for WordPress and an in-memory fake of the database layer, plus a small
set of integration tests against a real MySQL in CI.

Canonical key (pairs must give equal keys unless the test says differ):

- T1 (1, 2): `  https://example.com/a?id=1&amp;x=2  ` equals `https://example.com/a?id=1&x=2`; `&amp;amp;` decodes once only.
- T2 (3, 63): `ftp://example.com/a`, `example.com/a`, `javascript:alert(1)` and a headline string yield no key, pass through lookup unchanged, and are not recorded.
- T3 (4): `https://` and `http:///path` yield no key and fail open without a warning.
- T4 (5): `https://user:pw@example.com/a` equals `https://example.com/a`; no stored data or log contains `pw`.
- T5 (6): `http://example.com/a` equals `https://example.com/a`.
- T6 (7, 8): `https://example.com:443/a` and `http://example.com:80/a` equal `https://example.com/a`; `:8080` differs.
- T7 (9, 10): `https://EXAMPLE.com./a` equals `https://example.com/a`.
- T8 (11): with `intl`, `https://bücher.example/a` equals `https://xn--bcher-kva.example/a`; without `intl`, both yield keys and no error.
- T9 (12): `www.`, `m.`, `mobile.`, `amp.`, `www.m.` and no prefix all equal for `example.com/a`.
- T10 (13, 14): `https://m.com/a` keeps host `m.com`; `https://192.0.2.1/a` keeps its host.
- T11 (15): `https://www-example-com.cdn.ampproject.org/c/s/www.example.com/a/amp?utm_source=x` equals `https://example.com/a`; a nested AMP cache URL is unwrapped once only.
- T12 (16): `https://hindi.example.com/a` differs from `https://example.com/a`; with the host filter merging them, equal.
- T13 (17, 22): `https://example.com` equals `https://example.com/`; `/a/` equals `/a`.
- T14 (18): `/%7Ea` equals `/~a`; `/%e2%82%b9` equals `/%E2%82%B9`; `/a%2Fb` differs from `/a/b`.
- T15 (19): a raw UTF-8 path equals its percent-encoded form.
- T16 (20): `/A/Story` equals `/a/story`; with the filter off for a host, they differ.
- T17 (21, 25): `/a//b`, `/a/./b` and `/a/x/../b` equal `/a/b`.
- T18 (23): `/a/index.html` equals `/a`.
- T19 (24a to 24g): each AMP variant equals its plain path; `/camp/story` and `/example-amplifier` are unchanged.
- T20 (26, 27): `?UTM_Source=x`, `?fbclid=1`, `?gclid=1&ref=rss` all equal the bare URL, on slug paths and on script endpoints.
- T21 (28, 29): `?id=` and `?a[]=1` are dropped.
- T22 (30): on `/news/story`, `?page=2&foo=bar` is dropped; on `/news`, `?id=42` differs from `?id=43`.
- T23 (31): `/?p=1` differs from `/?p=2`; `/page.aspx?PRID=7&lang=1` differs from `/page.aspx?PRID=7`.
- T24 (32, 33, 34, 35): `?b=2&a=1` equals `?a=1&b=2` on a script endpoint; `?id=AbC` differs from `?id=abc`; `?id=1&id=2` equals `?id=2`; a URL with only tracking parameters has no `?` in its key.
- T25 (36): `/a#top` equals `/a`.
- T26 (37, 38): an article-id rule makes `/sports/x-123456` and `/cricket/y-123456` equal; the key filter can rewrite a key, and an empty return keeps the computed one.
- T27 (39): a table of 200 fixture URLs gives byte-identical keys on PHP 8.1, 8.2 and 8.3 and under a non-C locale.
- T28 (41, 42, 43): plain key of `HTTPS://WWW.Example.com/A/?b=1&a=2#f` is `example.com/a?b=1&a=2` (lower-cased, query order kept, trailing slash before the query removed); `/?p=1` and `/?p=2` have different plain keys.

Pre-processing and wrappers:

- T29 (44, 45): a pre-processing callback that maps URL X to Y makes a lookup of X match a record of Y; a callback returning null or an array is ignored.
- T30 (46, 65): a Google News `/rss/articles/` URL passes through lookup with zero queries and is not recorded; no warning is emitted and no counter moves.

Record path:

- T31 (47, 48, 49): inserting a draft post with a configured meta key holding a URL writes two rows; an `auto-draft` writes none; a page (not in the post type list) writes none; the should-record filter returning false writes none.
- T32 (49): with two meta keys configured, the first valid one wins; an invalid first value falls through to the second.
- T33 (50): a post inserted without meta and then given the meta is recorded once; insert plus meta in either order writes exactly two rows.
- T34 (51): the record function and the record action each write rows for a URL with and without a post id.
- T35 (52, 66): a source URL on the home host is not recorded and its lookup passes through.
- T36 (53): a URL whose canonical and plain keys are identical writes one row.
- T37 (54, 55): recording the same URL twice, from two posts, keeps the first post id and leaves two rows.
- T38 (56): with the table dropped, inserting a post succeeds, no PHP warning is emitted, and failed open goes up by one.
- T39 (57): revisions and autosaves write nothing.
- T40 (58): trashing and permanently deleting a post keeps its rows; with the filter on, permanent delete removes them.
- T41 (59): changing the meta URL on an existing post adds rows for the new URL and keeps the old ones.
- T42 (60): a lookup miss writes no row.

Lookup path:

- T43 (61, 62): the Echo callback is registered once; called with `true`, an integer, an array or an empty string, it returns the input unchanged with no query.
- T44 (64): with the setting off, the constant set, or the enable filter false, a seen URL is returned unchanged and checked does not move.
- T45 (67, 68): after recording `https://m.example.com/a/amp?utm_source=x`, a lookup of `https://example.com/a` returns boolean true, skipped goes up, the skipped action fires with the post id, and the row's last seen is refreshed once within an hour however many hits occur.
- T46 (67): a lookup matches a canonical row by the item's plain hash and a plain row by the item's canonical hash (cross-kind match).
- T47 (67, 86): a row whose last seen is past retention does not match even when prune has not run.
- T48 (69): a miss returns exactly the input string.
- T49 (70): two lookups of one URL in one request issue one query; a record between them makes the second return true without a query.
- T50 (71): the public function and the public filter answer true for a recorded URL and false for an unknown one and on every failure in T51.

Fail open:

- T51 (72, 73, 74, 75): for each of: table missing, database error on the query, schema marked failed, a third-party pre-processing callback that throws, a URL of 10,000 characters, the Echo callback returns the input unchanged, the public function returns false, failed open goes up, and no PHP notice, warning or uncaught exception escapes. The source contains no `@` operator (static check).
- T52 (76): after one database error, the next 100 lookups in that request issue no query.

Backfill:

- T53 (77, 78, 81): on 1,000 fixture posts with source meta, `--dry-run` writes nothing and reports counts; a real run writes the rows; a second run writes zero and reports them as already present.
- T54 (78, 80): `--from-id` resumes; posts are fetched by id keyset (the query contains no offset).
- T55 (79): the admin AJAX handler rejects a missing nonce and a user without `manage_options`, and resumes from the stored cursor.
- T56 (82, 83): posts older than retention are skipped unless `--include-expired`; rows written carry the post's GMT date as first and last seen.
- T57 (77, 58): trashed posts are backfilled; `auto-draft` posts are not.

Retention:

- T58 (84, 85): with retention 30, rows last seen 31 days ago are deleted by prune in batches; with 0, nothing is deleted.
- T59 (87): a row refreshed by a hit 10 days ago survives a 30 day prune although first seen 60 days ago.
- T60 (88): activation schedules one daily event, deactivation removes it, a must-use install schedules it on `init` when missing and never schedules a second.

Multisite:

- T61 (89, 91, 93): two sites, a URL recorded on site 1 does not match on site 2; counters and settings are separate.
- T62 (90, 92): network activation creates tables on all sites; a new site gets a table; a deleted site's table is dropped; a must-use install creates a site's table on first use.

Schema:

- T63 (94, 95, 96): a fresh install creates the table, verifies columns and indexes against the database, and only then stores the schema version.
- T64 (96, 97): with the stub reporting a column missing after the create (simulating a silent skip), the plugin runs an explicit alter, verifies again, and on a second failure leaves the schema version unchanged, shows the notice, and lookups fail open.
- T65 (98): a lookup request performs no schema check query.
- T66 (40, 99): rows of the previous key version still match during one retention period after a version bump; rows two versions old do not; plain rows match throughout.
- T67 (100): uninstall drops the table and deletes every option and event on every site.

Counters and admin:

- T68 (101, 102, 103): 500 lookups in one request cause exactly one counter write, at shutdown.
- T69 (104, 105): reset from admin requires nonce and capability; the counters filter and `wp skip-seen stats --format=json` return the same numbers.
- T70 (section 8): every admin output is escaped (static check plus a rendering test with a meta key containing `<script>`); all SQL uses prepared statements (static check).
- T71 (section 11): `wp skip-seen check <url>` prints both keys, both hashes and presence; `verify-schema` exits non-zero when the stub schema is missing a column.

Integration (CI, real MySQL and the WordPress test suite in one job):

- T72 (whole flow): a fake importer creates a post with source meta, then calls `apply_filters( 'echo_filter_dup_check', <variant URL> )`; the result is true. With the plugin inactive the same call returns the URL.

## 13. Open questions

The builder answers each in `docs/ARCHITECTURE.md`.

1. Default source meta keys. The plan ships an empty list so the plugin does
   not describe any importer's internals. Should the settings page instead
   offer a one-click "detect" that lists the meta keys on recent posts whose
   values are URLs, so the operator can pick one?
2. Echo callback return on a miss. The plan returns the incoming value
   unchanged (69), which preserves any other callback's answer and is what
   the hook expects. Confirm this over returning boolean false, and document
   the reason.
3. Hash function. MD5 in 16 binary bytes is compact and fast and collisions
   are irrelevant at this scale. Confirm, or justify a 64-bit hash with a
   collision check.
4. Should the URL be stored (truncated) for the admin page's "recent skips"
   view, at the cost of size and of storing feed URLs? The plan says no.
5. Case 20 lower-cases paths by default. Is a per-host opt-out enough, or
   should the default be the other way round?
6. The per-request cache cap (section 9): is a simple array with a cap
   enough, or is an LRU worth its code?
7. Must-use install: should table creation on first use run on any request,
   or only on admin and CLI requests (the plan), leaving cron-only sites
   failing open until an admin visits? Consider running it in cron too.
8. Site Health test thresholds (section 11).
9. Integration test harness: WordPress test suite in CI or a lighter
   approach that still exercises real `wpdb` and real MySQL.

## 14. Lessons from production, written as rules

1. Seen means imported. Record only after a post exists; never record on a
   check, or an item that fails to import is lost for good.
2. Fail open, always. A wrong pass costs one download that the importer's own
   check still catches; a wrong skip loses an article silently.
3. Only answer for URLs. The duplicate filter may carry something that is not
   a URL, or a value another callback already set; return anything you do
   not understand unchanged.
4. Leave redirect wrappers alone. Keying a Google News interstitial link
   gives a key no direct-feed copy will ever match; let an unwrap tool turn
   it into the publisher URL first, through the pre-processing filter.
5. One article has many URLs. Mobile hosts, AMP hosts, AMP paths, tracking
   parameters, trailing slashes and fragments all split one article into
   several items unless the key collapses them.
6. Never strip the query blindly. On a script endpoint the query is the page;
   only on slug paths can non-id parameters go.
7. When the key rules change, keep matching the old key. Rows written under
   the old rules must keep matching until they age out, or every article in
   the table is downloaded again the day the rules change. Keep the plain key
   frozen as the safety net.
8. Never record your own host. A fallback to the post's guid or permalink
   points at the site itself and poisons the table.
9. Meta can arrive late or not at all. Importers write source meta after the
   insert, and some code paths skip it; listen for the meta too, and give
   importers a direct way to record.
10. Verify the schema after every create or upgrade. WordPress's `dbDelta`
    has silently skipped column additions in production; read the real
    columns back, repair with an explicit alter, and do not store the new
    schema version until the check passes.
11. One counter write per request, not per item. Busy imports turn
    per-item option writes into a write storm.
12. Back off after the first database error in a request; do not issue one
    failing query per feed item.
13. Make every unit of work leave a mark. A record path that sometimes writes
    nothing for a post (because the URL was already present) must still
    finish that post cleanly, so nothing upstream retries it forever.
14. Measure the hit rate before and after. The case for this plugin was one
    number from the importer's log (82.5% of article downloads were already
    imported); the admin page keeps that number visible.
