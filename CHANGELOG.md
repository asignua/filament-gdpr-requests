# Changelog

All notable changes to `asignua/filament-gdpr-requests` are documented here.

## Unreleased

- Request register with deadline (one month, one extension), reminders, action log, four gates denied by default.
- Data map (`PersonalData`, `Erase`, `Rule`, `Retention`), explicit subject key, keyed HMAC pseudonyms (`GDPR_PSEUDONYM_KEY`).
- Export archive on a private disk with a signed, user-bound, short-lived link.
- Erasure with a schema-aware preview that blocks unsafe rules, typed confirmation, four eyes, one transaction per group,
  `partial` status and an idempotent forward-only rerun. Tested on SQLite, MariaDB 11.4 and PostgreSQL 16.
- `gdpr:retention` (dry run by default), `gdpr:prune`, `gdpr:remind`.
- Ten languages (eight machine-translated).
