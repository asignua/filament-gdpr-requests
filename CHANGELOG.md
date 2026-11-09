# Changelog

All notable changes to `asignua/filament-gdpr-requests` are documented here.

## v1.0.1 - 2026-11-09

- Security hardening for Filament 5.11.2+, where resource pages ask `getXAuthorizationResponse()` instead of `canX()`: the create rule (`gdpr.manage`) and the "never edit / delete" rules now live in the resource's `getCreateAuthorizationResponse()` / `getEditAuthorizationResponse()` / `getDeleteAuthorizationResponse()` / `getDeleteAnyAuthorizationResponse()`, so a page can no longer open for a user the resource meant to refuse. The register, view, create and erasure pages are covered by HTTP tests for the wrong and the right user.

## v1.0.0 - 2026-11-09

- A register for data-subject requests (access, erasure, rectification, restriction, portability, objection) in a
  Filament 5 panel: deadline of one calendar month with one extension, reminders, assignee, notes and an action log.
  Names, addresses and notes are wiped after `request_retention_days`; a keyed pseudonym stays.
- Requires PHP 8.3+, Laravel 12 or 13, Filament 5, `ext-zip`, one database connection and `GDPR_PSEUDONYM_KEY` (HMAC key
  for the pseudonyms and the plan fingerprint; rotation through `GDPR_PSEUDONYM_KEY_VERSION`).
- Four gates (`gdpr.view`, `gdpr.manage`, `gdpr.export`, `gdpr.erase`), **denied until you define them**.
- A data map in code (`PersonalData`, `Erase`, `Rule`, `Retention`) with an explicit subject key; nothing is guessed.
- Export: a zip of JSON plus declared files on a private disk, behind a short-lived signed link bound to its user.
- Erasure: a schema-aware preview that blocks unsafe rules (foreign keys, unique columns, shared files, retained rows,
  several records behind one address), a typed confirmation, **four eyes**, one transaction per group, `partial` with a
  way forward, and a re-check of the data before a request reads `fulfilled`.
- `gdpr:retention` (dry run unless `--apply`), `gdpr:prune`, `gdpr:remind`.
- Tested on SQLite, MariaDB 11.4 and PostgreSQL 16. Before any work the plugin probes the server's case folding and
  refuses a database whose `LOWER` folds less than PHP's (a Postgres `LC_CTYPE 'C'`). Large tables: declared indexed
  path columns or a lookup closure keep the shared-file check off a full read.
- Ten languages (English and Ukrainian by the author, eight machine-translated).
- **Not legal advice and not "GDPR compliance"**: a process tool that keeps evidence. Not covered: backups, logs outside
  the database, search indexes and caches, external systems, tables missing from the map, online identity checks, a
  public request form, the record of processing.
- Panels with tenancy are not supported in v1.
