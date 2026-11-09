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
- A second declaration of the same map group is refused instead of silently replacing the first one (whose rows were then
  left out while the request read `fulfilled`). `PersonalDataRegistry::forget()` replaces an entry on purpose.
- All archives of the person are purged before an erasure reads `fulfilled`; one that survives keeps it `partial`.
- `gdpr:remind` marks only what reached somebody and warns about a missing `notifyAdminsUsing()`.
- The schema reader stays inside the current database (Laravel's `getTables()` without a schema lists them all on
  MariaDB), foreign keys across databases included. This was the cause of the intermittent real-database test errors.
- Notes are refused after the details were wiped.

- **`fulfilled` means the data was looked at again, not that the steps finished.** Finalisation re-plans for the same
  person. A row the plan said would go, a file that should be gone, a mismatch of rows planned and handled, a changed map
  or a blocker keep the request `partial`, with a precise list on the erasure page and in the log.
- A first run that finds fewer rows than confirmed fails the group. Closure groups run before the tables they read; when
  foreign keys forbid that, the preview blocks. A callback that changes its own output blocks the preview.
- **Several records behind one address block erasure and export**, soft-deleted ones included. Ada's erasure could delete
  Eve's ticket and Ada's export could hold it. An address that turns up on another record after the root was erased stops
  the run too.
- **One normal form for an address on every driver.** SQL's TRIM strips spaces only, so tab, newline, CR and no-break space
  survived an erasure. The characters are now one list for PHP and SQL; on SQLite PHP itself answers, because its LOWER
  knows only ASCII. The pseudonym of an address with such characters changes accordingly.
- A table mapped only for another subject no longer passes as mapped, in the retention plan either.
- `files()` with `Erase::delete()` blocks: the file was orphaned and the request read `fulfilled`.
- Rows that hold almost the key (case, padding) block erasure and export instead of vanishing silently, on every driver.
- **`partial` has a way forward.** After a new row or a fixed map, resume, confirm, refuse and relink were all refused. A
  fresh preview and confirmation now work from `partial` and from a run that went quiet, four eyes included. Every group
  runs again; step figures add up, the earlier result stays in the step's `detail`, files still owed stay owed, and the
  log keeps every round. Relinking and refusing stay refused once rows are gone.
- **A fulfilled erasure closes itself**: `closed_at` is set and the link cut at finalisation, so `gdpr:prune` can wipe the
  name, address and notes. The status stays `fulfilled`; a second close is refused.
- Keys past PHP's integer range are refused before any query and match nothing: they clamped to row
  9223372036854775807 on unsigned MariaDB keys and aborted the transaction on Postgres. Keys are compared as text.
- Rule output is compared as text, so a masked 19-digit value counts as changed. A date in a datetime column, reordered
  json and padded `char` values count as unchanged.

- A closure's own `ORDER BY` no longer breaks the chunked read (15 of 25 rows were left while the request read
  `fulfilled`): the order is dropped, and a limit, offset, `DISTINCT`, `GROUP BY` or `UNION` blocks the preview. The check
  after the run counts with a fresh, unchunked query, so it does not share a fault of the read it checks.

- A subquery that a `matchedUsing()` closure builds through another model no longer keeps that model's soft-delete (or
  any other global) scope: the items of a soft-deleted order were left behind. Closures run with global scopes off.

- A closure that reads its own table now runs before another group that deletes from that table (it ran after, found
  nothing, and two rows stayed). Fewer rows than confirmed are excused only by a deletion that ran earlier.

- An entry that anonymizes or deletes rows a `retain` entry keeps blocks the preview (retained invoices were deleted while
  the report said they were kept).

- An anonymization that changes where a row names its file (the path nulled or replaced) without `files: true` blocks
  the preview, like a deletion that leaves files behind; the file was orphaned.

- A file that a row of another record still names is no longer removed: the preview (and every group run, and
  `gdpr:retention`) blocks when a path to remove is named by a row that stays, listing tables only.

- **Complete** and **Refuse** set `closed_at`, so `gdpr:prune` wipes those requests after `request_retention_days`
  (before, only an explicit close or a finished erasure did, and the details of the others stayed for ever). Closing such
  a request later keeps the original date; requests that ended before this change are wiped by their last update.

- The report after a run shows the legal basis and the kept-until date of retained groups (it showed them only in the
  live preview).

- A partial erasure whose record changed its address had no way forward (the mismatch blocked, and the address could
  not be taken over). **Accept the record's e-mail** now works there until the record itself is erased: the request stays
  partial, and needs a new identity check and a new confirmation.

- Postgres missed an address with `İ` (PHP lowercases it to two code points) or a word-final `Σ`. The query folds both
  on MariaDB and Postgres and PHP still decides; the lookup by e-mail compares in PHP too. README says what stays with
  the server's Unicode tables.

- `testbench.yaml` (with its demo keys) is no longer in the package archive.

### Changed

- The preview names the person and the record at the top; on a phone its rows become cards and no column is hidden.
- Rules, stored errors and the action log read as translated text in ten languages. Errors are stored as a translation key
  with parameters; rows written earlier are shown as they are.
- A missing `GDPR_PSEUDONYM_KEY` says how to fix it, on the first page of the panel.
- The README map covers `password`, `remember_token` and spatie media, and warns that anything outside the map stays.
