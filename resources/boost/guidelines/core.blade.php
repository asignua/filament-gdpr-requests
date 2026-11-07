## Filament GDPR Requests (asignua/filament-gdpr-requests)

- Register on the panel: `->plugin(Asignua\FilamentGdprRequests\GdprRequestsPlugin::make())`. Panels with tenancy are not supported.
- Set `GDPR_PSEUDONYM_KEY` (32+ chars). The plugin never falls back to `APP_KEY`.
- Define the gates `gdpr.view`, `gdpr.manage`, `gdpr.export`, `gdpr.erase`; they are denied until defined.
- Declare the data map in a service provider's `boot()` with `PersonalData::for(Model::class)`: one `->subject()` root, other
  entries `->of(Root::class)->matchedBy('fk')` / `matchedByEmail()` / `matchedUsing()`. Columns without a `Rule` are never
  changed; there are no defaults. Strategies: `Erase::anonymize([...])`, `Erase::delete()`, `Erase::retain($basis, days: N, from: 'col')`.
- Never search for subjects with LIKE and never add an "erase everything" shortcut: erasure is preview, typed confirm, then a different person runs it.
- Schedule `gdpr:prune` and `gdpr:remind`; `gdpr:retention` is a dry run unless `--apply` is passed explicitly.
