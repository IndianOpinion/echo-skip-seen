# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-11-11

### Added

- Callback on Echo RSS Post Importer's `echo_filter_dup_check` filter that
  returns true for an item URL whose article has already been imported, and
  the value unchanged in every other case.
- Canonical key (key version 1) that collapses mobile and AMP hosts, the
  Google AMP cache, AMP paths, tracking parameters, fragments, trailing
  slashes, default ports, `index` files, dot segments and percent-encoding
  case, and keeps the query on script endpoints. Frozen plain key as an
  exact-match safety net.
- Per-site table of MD5 key hashes; no URLs stored.
- Record path on post insert and on source meta add or update, plus
  `echo_skip_seen_record()` and the `echo_skip_seen_record_url` action.
- `echo_skip_seen_is_seen()`, the `echo_skip_seen_is_seen` filter and
  `echo_skip_seen_keys()` for other importers and tools.
- Fail-open handling with a per-request circuit breaker, and a schema check
  that reads the real columns and indexes back and repairs them.
- Retention (90 days by default) with a daily batched prune.
- Backfill from existing posts through WP-CLI and an admin button.
- Tools page with status, counters, settings, backfill and a URL checker;
  Site Health test; WP-CLI commands `backfill`, `check`, `record`, `stats`,
  `prune` and `verify-schema`.
- Multisite support, must-use install through a loader file, and uninstall
  that removes everything on every site.

[Unreleased]: https://github.com/IndianOpinion/echo-skip-seen/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/IndianOpinion/echo-skip-seen/releases/tag/v0.1.0
