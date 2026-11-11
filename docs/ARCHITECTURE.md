# Architecture

This document describes how echo-skip-seen is built and records every
decision taken while building it from [PRD.md](PRD.md). Section numbers
such as "PRD 67" point at the numbered cases in the PRD.

## Modules

All classes live under `IndianOpinion\EchoSkipSeen` in `src/`.

| Module | Files | Job |
|---|---|---|
| Entry points | `echo-skip-seen.php`, `uninstall.php`, `src/autoload.php`, `src/functions.php` | Plugin header, autoloader for installs without Composer, the three global functions. |
| Wiring | `Plugin.php` | Registers hooks and builds services lazily. Holds the shared instance. |
| Keys | `Keys/Url.php`, `Keys/Canonicalizer.php`, `Keys/PlainKey.php`, `Keys/PercentEncoding.php`, `Keys/Ascii.php`, `Keys/Regex.php`, `Keys/Rules.php`, `Keys/KeySet.php`, `Keys/KeyFactory.php` | Pure URL normalisation. Runs without WordPress except `Rules::fromFilters()`. |
| Admission | `UrlGate.php` | Runs the pre-processing filter; leaves alone non-URLs, wrapper hosts and the site's own host. |
| Lookup | `Lookup.php` | Echo callback, public question, per-request cache, circuit breaker, last-seen refresh. |
| Record | `Recorder.php`, `PostWatcher.php`, `SourceUrl.php` | Post and meta hooks, the "counts as imported" decision, writing rows. |
| Storage | `Db/Db.php`, `Db/Table.php`, `Db/Schema.php` | The only code that talks to `$wpdb`; table reads and writes; create, verify, repair. |
| Versions | `KeyVersions.php` | Which canonical key versions a lookup accepts. |
| Backfill | `Backfill.php`, `BackfillRequest.php`, `BackfillResult.php` | Keyset walk over existing posts. |
| Retention | `Retention.php` | Batched prune on a daily event. |
| Lifecycle | `Installer.php`, `Uninstaller.php` | Activation, deactivation, init check, network sites, uninstall. |
| Observability | `Counters.php`, `Log.php`, `FailOpen.php`, `Admin/AdminPage.php`, `Admin/UrlReport.php`, `Admin/SiteHealth.php`, `Cli/Command.php` | Counters, debug log, Tools page, Site Health test, WP-CLI. |
| Settings | `Settings.php` | One option array per site, validated on read and write. |

## Data flow

### Lookup (PRD 4.6)

1. Echo applies `echo_filter_dup_check` to an item URL. `Lookup::echoFilter()`
   returns anything that is not a non-empty http or https string unchanged,
   without touching settings or the database.
2. If the plugin is disabled (constant, setting, filter), the value goes
   back unchanged and nothing is counted.
3. `UrlGate::admit()` runs `echo_skip_seen_url` (context `lookup`). A wrapper
   host or the site's own host returns the value unchanged, with no query and
   no counter.
4. `KeyFactory` computes both keys and their MD5 hashes. A URL that cannot be
   keyed (no host, longer than 4,096 bytes) fails open.
5. The per-request cache answers repeats. Otherwise, if the circuit breaker
   is open or the stored schema version is not current, the lookup fails
   open. No schema query runs on this path (PRD 98).
6. One query: `SELECT ... WHERE key_hash IN (UNHEX(%s), UNHEX(%s))`. Rows
   past the retention cutoff and canonical rows of versions no longer
   accepted are ignored in PHP.
7. A match returns `true`, counts a skip, refreshes `last_seen` when it is
   more than an hour old, and fires `echo_skip_seen_skipped`. A miss returns
   the exact input.

### Record (PRD 4.5)

1. `wp_after_insert_post`, `added_post_meta` and `updated_post_meta` (for a
   configured source meta key only) reach `PostWatcher`.
2. Revisions and autosaves stop here. The first configured meta value that
   is an http or https URL is the source URL.
3. The default decision is post type in the list and status not
   `auto-draft`; `echo_skip_seen_should_record` has the last word.
4. A per-request marker (site, post id, URL) makes the second of the insert
   and meta events a no-op, whether or not the first one succeeded.
5. `Recorder` admits the URL (context `record`), computes keys and runs one
   `INSERT IGNORE` for both rows. When fewer rows were new than written, one
   `UPDATE` moves `last_seen` forward on the existing rows.
6. The lookup cache learns the new hashes, the recorded counter goes up by
   the number of new rows, and `echo_skip_seen_recorded` fires.

### Lifecycle

- **Activation** creates the settings option (not autoloaded), creates and
  verifies the table, syncs the key version and schedules the daily prune.
  Network activation does this on every site, 100 sites per `get_sites()`
  call.
- **Every request, on `init` (priority 5)**: if the stored schema version is
  missing or old, install under a 60 second transient lock, at most once an
  hour after a failure. Then sync the key version and schedule the prune if
  it is missing. This is what makes the must-use install work.
- **Deactivation** removes the scheduled event and keeps the data.
- **Uninstall** drops the table and deletes every option, the lock transient
  and the event, on every site.
- **New site** (`wp_initialize_site`): installs when the plugin is network
  active. **Deleted site**: `wpmu_drop_tables` drops its table.

## Data

Table `{prefix}echo_skip_seen`, one per site:

| Column | Type | Notes |
|---|---|---|
| `key_hash` | `binary(16)` | MD5 of the key. Primary key. |
| `key_kind` | `tinyint unsigned` | 1 canonical, 2 plain. |
| `key_version` | `smallint unsigned` | Canonical rule version; 0 for plain rows. |
| `post_id` | `bigint unsigned` | 0 when unknown. Indexed for delete-on-post-delete. |
| `first_seen`, `last_seen` | `datetime` | UTC. `last_seen` is indexed for retention. |

Options, all per site:

| Option | Autoload | Content |
|---|---|---|
| `echo_skip_seen_settings` | no | enabled, retention days, meta keys, debug. |
| `echo_skip_seen_counters` | no | Counts, "since" times, 24 hourly buckets of checked and skipped. |
| `echo_skip_seen_db_version` | yes | Schema version, written only after verification. |
| `echo_skip_seen_schema_check` | no | Last check: ok, missing parts, time. |
| `echo_skip_seen_key_versions` | yes | Current and previous canonical key version, and when it changed. |
| `echo_skip_seen_backfill_cursor` | no | Admin backfill cursor and running totals. |

Transient: `echo_skip_seen_install_lock`. Scheduled event: `echo_skip_seen_prune` (daily).

## Answers to the PRD's open questions

1. **Default source meta keys.** The list ships empty and the plugin has no
   "detect" helper. The operator sets the keys on the Tools page, with the
   `echo_skip_seen_source_meta_keys` filter, or with
   `wp skip-seen backfill --meta-key=<keys>` (saved to the settings unless
   `--dry-run`). The page explains how to find the key and warns when the list
   is empty. A detector would have to guess which URL-valued meta is the
   source, and a wrong guess (a featured image URL, a canonical link to the
   site itself) would poison the table quietly.
2. **Echo callback return on a miss.** The callback returns the value it
   received. Returning `false` would overwrite another callback's `true` at
   an earlier priority, and it would put a different type into a filter
   whose input is a URL string. The hook's contract is "true means skip";
   everything else is "not decided here".
3. **Hash function.** MD5, 16 bytes, in a `BINARY(16)` primary key. It is
   fast, built in, fixed width, and at a few million rows the chance of an
   accidental collision is negligible. A collision could only cause one wrong
   skip, and nobody gains from forging one. Hashes cross the connection as
   hex through `UNHEX()`, so no binary string passes through the character
   set.
4. **Storing URLs.** No URL is stored and there is no "recent skips" view.
   Operators who want a list hook `echo_skip_seen_skipped` (see
   `examples/log-skips.php`) or turn on debug logging; `wp skip-seen check`
   and the Tools page recompute keys from any URL.
5. **Path case.** Paths keep their case by default. This reverses PRD case
   20: lower-casing by default would merge two articles on any site whose
   paths are case-sensitive, and a wrong skip loses an article silently while
   a wrong pass costs one download. `echo_skip_seen_lowercase_path` opts a
   host in (see `examples/configure.php`). Acceptance test T16 is inverted
   to match.
6. **Per-request cache.** A capped array, not an LRU. Hits are keyed by hash
   and misses by the pair of hashes, each capped at 5,000 entries; when a cap
   is reached the oldest entries are dropped. One import run rarely repeats a
   URL far apart, so recency tracking would cost code and buy nothing.
7. **Table creation on first use.** On `init`, on any request type
   (front end, admin, cron, CLI, AJAX), whenever the stored schema version is
   missing or older than the code's. A 60 second transient lock stops two
   requests from installing at once, and after a failure `init` waits an hour
   before trying again so a broken database is not hit on every request. The
   schema is verified after `dbDelta` and repaired with a direct `ALTER
   TABLE` when a column or index is missing. Cron-only sites therefore never
   wait for an admin visit. The check costs nothing when the table is ready:
   the version option is autoloaded.
8. **Site Health.** One direct test. Critical when the table is missing or
   the schema check failed (it names what is missing). Otherwise good, with
   the row count. Recommended when the table is empty and no source meta key
   is set (the PRD's "empty meta key list" case). An informational note, not
   a status change, when more than 95 percent of the checks in the last 24
   hours were skips, with at least 20 checks so a quiet hour does not raise
   it. The 24 hours come from hourly buckets in the counters option.
9. **Integration tests.** Automated tests run on the stub layer in
   `tests/stubs/`: WordPress functions backed by in-memory state, and a fake
   `wpdb` that understands exactly the statements the plugin issues and can
   simulate errors, a column that `CREATE` silently skips, and a failing
   `ALTER`. Integration against real WordPress and MySQL (T72) is a manual
   checklist in [CONTRIBUTING.md](../CONTRIBUTING.md).

## Other decisions

- **Plain key strips user information.** PRD 41 does not mention it, but
  PRD 5 says user information is never stored, and a hash of a password is
  still derived from it. The plain key removes `user:pass@` before hashing.
  This is part of the frozen definition from 0.1.0.
- **Wrapper hosts.** Any URL on a wrapper host is left alone, not only paths
  containing `/articles/`. Keying other Google News paths would produce keys
  that no direct feed copy can match.
- **Own host.** Compared after the same host normalisation as the key (so
  `m.` and `www.` variants of the site count as the site), per site on a
  network.
- **Disabled means off.** When disabled, lookups pass through and the record
  path writes nothing. Backfill still runs when asked for explicitly.
- **Invalid URLs.** On the lookup path a URL that starts with http or https
  but cannot be keyed counts as failed open. A value that is not an http or
  https URL at all is not ours: it passes through without counting. On the
  record path neither is counted; they are ignored.
- **Circuit breaker.** One database error (or any exception) on the lookup
  query opens the breaker for the rest of the request. Lookups after that
  count as failed open but log nothing, so the log gets one line, not one per
  item.
- **Key version window.** `echo_skip_seen_key_versions` remembers the
  previous version and when the current one arrived. A lookup accepts the
  previous version until one retention period has passed (forever when
  retention is 0). Older versions never match. Plain rows are version 0 and
  always match.
- **Article id rules** produce `host/#id=<id>` as the canonical key. A rule
  whose pattern does not compile is skipped; the warning PHP raises is caught
  with a temporary error handler, never with the `@` operator.
- **Determinism.** Only the 26 ASCII letters are case-folded (`strtolower`
  follows the locale on PHP 8.1). Unicode hosts use `idn_to_ascii` with UTS 46
  non-transitional processing when `intl` is loaded.
- **SQL.** Table names go into `$wpdb->prepare()` as `%i` identifiers
  (WordPress 6.2 and later), so every statement with a value is a literal
  template plus arguments. The only statements without `prepare()` are the
  `CREATE TABLE` passed to `dbDelta` and its `IF NOT EXISTS` fallback, built
  from the site prefix, the charset clause and constants. Database error
  output is suppressed around each statement and `last_error` is read after
  it.
- **Counters** flush on `shutdown`, hooked only after the first increment,
  so a page view that never looks anything up adds no shutdown work.
  Increments, "since" times and the hourly buckets go in one option write.
- **Multisite caches.** Lookup caches, the settings cache, the own-host value
  and the record markers are keyed by site, so an importer that calls
  `switch_to_blog()` in one request never gets another site's answer.
- **Backfill dates.** Rows get the post's GMT date as first and last seen.
  Drafts with a zero GMT date fall back to the local date.
- **Backfill admin runner.** One batch of 500 per AJAX request; totals and
  the last id are stored, so pressing the button again resumes. A finished
  run starts from the beginning next time; "Start again" restarts at once.
- **Priority.** `ECHO_SKIP_SEEN_PRIORITY` wins, then the
  `echo_skip_seen_priority` filter, which must be added before the plugin
  loads (from a must-use plugin).

## Testing

- `tests/Unit`: canonical and plain keys (T1 to T28), settings validation,
  and the 200-URL determinism fixture (`tests/fixtures/keys.json`, generated
  by `tests/fixtures/generate.php`) run under a non-C locale.
- `tests/Feature`: record path, lookup path, fail open, backfill, retention,
  multisite, schema, counters, admin handlers, Site Health and WP-CLI on the
  stub layer, including static checks that the source has no `@` operator
  and that only `Db.php` calls `$wpdb` query methods.
- phpcs runs PSR-12 plus the WordPress escaping, nonce, input and prepared
  SQL sniffs. PHPStan runs at level 8 with the WordPress stubs.
