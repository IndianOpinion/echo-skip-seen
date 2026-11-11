# Security policy

## Supported versions

| Version | Supported |
|---|---|
| 0.1.x | yes |

## Reporting a vulnerability

Please do not open a public issue for a security problem.

Email **hello@indianopinion.org** with:

- what you found and where (file, function, hook);
- how to reproduce it, and the WordPress and PHP versions;
- what an attacker could do with it.

We acknowledge reports within 3 working days and aim to send a fix or a
plan within 14 days. We credit reporters in the release notes unless you ask
us not to.

## Scope

The plugin makes no network requests and runs no shell commands. Areas
worth attention: the admin page and its AJAX handler (nonces, capability
checks, escaping), SQL built in `src/Db/`, and regular expressions supplied
through the `echo_skip_seen_article_id_rules` filter.
