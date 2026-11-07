<?php

declare(strict_types=1);

return [
    /*
     * Four eyes: the person who runs an erasure must not be the one who confirmed it. Turn this off only when you
     * really are a single administrator. Every erasure run while it is off is written to the request log.
     */
    'four_eyes' => env('GDPR_FOUR_EYES', true),

    /*
     * Pseudonyms of data subjects are HMACs with their own key. The plugin refuses to work without it and never
     * falls back to APP_KEY. To rotate: set a new key and raise key_version, move the old key to previous_keys
     * (version => key) so that older pseudonyms can still be matched.
     */
    'pseudonym' => [
        'key' => env('GDPR_PSEUDONYM_KEY'),
        'key_version' => (int) env('GDPR_PSEUDONYM_KEY_VERSION', 1),
        'previous_keys' => [],
    ],

    /*
     * Exports: a private disk (never one with public visibility), the folder on it, the days an archive is kept
     * and the minutes a signed download link lives.
     */
    'export' => [
        'disk' => env('GDPR_EXPORT_DISK', 'local'),
        'directory' => 'gdpr-exports',
        'retention_days' => (int) env('GDPR_EXPORT_RETENTION_DAYS', 30),
        'link_minutes' => 15,
    ],

    /*
     * Days after the request is closed when the name, address, message and notes are wiped from the request
     * record (the pseudonym stays). Applied by `gdpr:prune`.
     */
    'request_retention_days' => (int) env('GDPR_REQUEST_RETENTION_DAYS', 90),

    /*
     * Art. 12(3): one month, extendable by up to this many further months, with a reason.
     */
    'extension_max_months' => 2,

    'reminders' => [
        // Days before the due date when the assignee is reminded.
        'days_before' => 5,
        // Notification channels; the default needs no table, add 'database' if you use Filament notifications.
        'channels' => ['mail'],
    ],

    // Mail to the person who made the request about the stages of their request. Off by default.
    'requester_mail' => [
        'enabled' => false,
    ],

    'erasure' => [
        // A confirmation older than this many hours must be given again.
        'confirmation_valid_hours' => 72,
        // A run with no heartbeat for this many minutes is considered dead and may be resumed.
        'stale_run_minutes' => 30,
        // Rows per chunk while scanning and updating.
        'chunk' => 200,
    ],

    'queue' => [
        'connection' => env('GDPR_QUEUE_CONNECTION'),
        'name' => env('GDPR_QUEUE'),
    ],
];
