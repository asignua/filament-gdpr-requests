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

### Fixed before the first release

- **The linked record decides who a request is about.** E-mail groups match on the root row's address. A differing
  address, a missing root row or an unknown address block erasure and export; a person settles a difference (new action
  **Accept the record's e-mail**). The address is kept at confirmation for groups that run after the root is anonymized.
- Relinking is refused once an erasure changed data, and removes the previous person's archives. Steps are bound to the
  plan fingerprint (new column `gdpr_request_steps.fingerprint`), so a step of another person never counts.
- Keys are stored as the database stores them: `001` binds as `1`, `1abc` is refused, and a key that matches no row blocks.
- `Rule::hash` accepts only its exact digest format. `Rule::fixed(null)`, and a callback that returns null, block on a
  NOT NULL column.
- A group failed by its files finishes on a successful retry. Duplicate or late jobs leave settled steps alone.
- The completion mail goes after the commit. Killed jobs reach `partial` through `failed()`; a quiet run offers Resume
  after `erasure.stale_run_minutes`.
- Failed file deletes are checked and reported; `gdpr:prune` and `gdpr:retention --apply` exit with a failure.
- The plan fingerprint is an HMAC with the pseudonym key, so it no longer gives the subject id back.
- `refuse`, `close` and `complete` are guarded by the database against a stale model.
- All archives of the person are purged before an erasure reads `fulfilled`; one that survives keeps it `partial`.
- `gdpr:remind` marks only what reached somebody and warns about a missing `notifyAdminsUsing()`.
- The schema reader stays inside the current database (Laravel's `getTables()` without a schema lists them all on
  MariaDB), foreign keys across databases included. This was the cause of the intermittent real-database test errors.
- Notes are refused after the details were wiped.

### Changed

- The preview names the person and the record at the top; on a phone its rows become cards and no column is hidden.
- Rules, stored errors and the action log read as translated text in ten languages. Errors are stored as a translation key
  with parameters; rows written earlier are shown as they are.
- A missing `GDPR_PSEUDONYM_KEY` says how to fix it, on the first page of the panel.
- The README map covers `password`, `remember_token` and spatie media, and warns that anything outside the map stays.
