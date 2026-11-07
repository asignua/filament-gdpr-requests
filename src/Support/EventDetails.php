<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Support;

use Asignua\FilamentGdprRequests\DataMap\PersonalDataRegistry;

/**
 * The context of a log line as a reader wants it: labelled, translated, group names instead of class names. The stored
 * context stays structural; this is only how it is shown.
 */
final class EventDetails
{
    /** Internal values that mean nothing to a reader. */
    private const array HIDDEN = ['fingerprint'];

    /**
     * @param array<string, mixed>|null $context
     */
    public static function format(?array $context): ?string
    {
        $parts = [];

        foreach ($context ?? [] as $key => $value) {
            if ($value === null || in_array($key, self::HIDDEN, true)) {
                continue;
            }

            $parts[] = self::label($key).': '.self::value($key, $value);
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private static function label(string $key): string
    {
        $text = Lang::t('context.'.$key);

        return str_contains($text, 'filament-gdpr-requests::') ? $key : $text;
    }

    private static function value(string $key, mixed $value): string
    {
        return match (true) {
            is_bool($value) => Lang::t($value ? 'context.yes' : 'context.no'),
            $key === 'group' => self::group((string) $value),
            $key === 'open' => implode(', ', array_map(static fn (mixed $group): string => self::group((string) $group), (array) $value)),
            $key === 'counts' => self::counts((array) $value),
            $key === 'model' => class_basename((string) $value),
            $key === 'type' => Lang::t('type.'.$value),
            $key === 'channel' => Lang::t('channel.'.$value),
            $key === 'action' => Lang::t('action.'.$value),
            $key === 'reason' && $value === 'files' => Lang::t('context.files'),
            $key === 'export' => strtoupper(substr((string) $value, -8)),
            $key === 'bytes' => number_format(((int) $value) / 1024, 1).' KB',
            is_array($value) => implode(', ', array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value)),
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    /**
     * @param array<mixed> $counts group => rows
     */
    private static function counts(array $counts): string
    {
        $parts = [];

        foreach ($counts as $group => $rows) {
            $parts[] = self::group((string) $group).': '.(is_scalar($rows) ? (string) $rows : '');
        }

        return implode(', ', $parts);
    }

    private static function group(string $key): string
    {
        return app(PersonalDataRegistry::class)->find($key)?->getLabel() ?? class_basename($key);
    }
}
