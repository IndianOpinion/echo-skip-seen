# Contributing

Thank you for helping. Bug reports, fixes and new key rules with a test are
all welcome.

## Setup

You need PHP 8.1 or later and Composer.

```sh
git clone https://github.com/IndianOpinion/echo-skip-seen.git
cd echo-skip-seen
composer install
```

No WordPress install is needed for the automated tests.

## Tests and checks

```sh
composer test      # PHPUnit
composer analyse   # PHPStan, level 8
composer lint      # phpcs: PSR-12 plus WordPress security sniffs
composer check     # all three
```

- Tests run on a stub layer in `tests/stubs/`: WordPress functions backed by
  in-memory state, and a fake `wpdb` that understands the statements the
  plugin issues. If you add a statement, teach `tests/stubs/FakeWpdb.php` to
  answer it.
- Every case in `docs/PRD.md` section 4 maps to at least one test. Name the
  case or acceptance test (for example `T45`) in a comment.
- `tests/fixtures/keys.json` pins the keys of 200 URLs. If you change a
  default key rule on purpose, raise `Canonicalizer::VERSION`, regenerate the
  fixture with `php tests/fixtures/generate.php`, and say why in the
  CHANGELOG. Never change `PlainKey`.
- CI runs the same checks on PHP 8.1, 8.2 and 8.3. Only use PHP 8.1 syntax.

## Style

- PSR-12, `declare(strict_types=1);` in every file, namespace
  `IndianOpinion\EchoSkipSeen`.
- No `@` error suppression, no `eval`, no shell, no network calls.
- Every SQL statement with a value goes through `Db`, with `%i` for the
  table name. Every admin output is escaped. Every admin handler checks a
  nonce and `manage_options`.
- Fail open: nothing in the plugin may stop an import.
- Prose: short sentences, plain words, British spelling.

## Pull requests

- One topic per pull request, with tests.
- Update `CHANGELOG.md` under "Unreleased".
- Keep `main` green: `composer check` must pass.
- A sign-off line (DCO) is not required.

## Manual integration checklist (acceptance test T72)

Automated tests do not run against real WordPress and MySQL. Before a
release, run this on a disposable site (for example with `wp-env` or a local
LAMP stack), WordPress 6.4 or later:

1. Install and activate the plugin. `wp skip-seen verify-schema` prints
   "Schema ok". `wp db query "SHOW CREATE TABLE wp_echo_skip_seen"` shows
   `key_hash binary(16)` as the primary key and indexes on `post_id` and
   `last_seen`.
2. `wp option update echo_skip_seen_settings '{"meta_keys":["source_url"]}' --format=json`
   (or set the key on Tools, Echo Skip Seen).
3. Create a post the way an importer would:
   `wp post create --post_title=Test --post_status=draft --meta_input='{"source_url":"https://www.example.com/2026/11/story/"}'`
4. `wp eval 'var_dump( apply_filters( "echo_filter_dup_check", "https://m.example.com/amp/2026/11/story?utm_source=rss" ) );'`
   prints `bool(true)`.
5. Deactivate the plugin and run step 4 again: it prints the URL string.
6. Reactivate. `wp skip-seen stats --format=json` shows `skipped` at 1 or
   more after step 4 (counters flush at the end of each request).
7. `wp skip-seen backfill --dry-run`, then `wp skip-seen backfill`: the
   second real run reports every row as already present.
8. Drop the table (`wp db query "DROP TABLE wp_echo_skip_seen"`), run step 4:
   it prints the URL string with no PHP warning. `wp skip-seen verify-schema`
   recreates the table.
9. On a multisite install, network-activate, create a site, check its table
   exists, delete the site, check its table is gone.
10. Uninstall from the Plugins screen: the table and every
    `echo_skip_seen_%` option are gone.
11. With Echo RSS Post Importer installed, run one feed twice. The second run
    downloads no article page for items already imported (check Echo's log),
    and the Tools page skip rate rises.
