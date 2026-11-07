<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Services;

use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Asignua\FilamentGdprRequests\Models\GdprRequestEvent;
use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

/**
 * The action log of a request. It records structure (what happened, how many rows), never the subject's name,
 * address or free text. When asignua/filament-activity-log-plus (spatie activitylog) is installed the same line
 * is written there too, under the log name "gdpr".
 */
final class RequestLog
{
    /**
     * @param array<string, mixed> $context
     */
    public static function record(GdprRequest $request, string $event, ?Authenticatable $actor = null, array $context = []): GdprRequestEvent
    {
        $row = GdprRequestEvent::query()->create([
            'request_id' => $request->getKey(),
            'event' => $event,
            'actor_id' => $actor === null ? null : (string) $actor->getAuthIdentifier(),
            'context' => $context === [] ? null : $context,
            'created_at' => now(),
        ]);

        self::mirror($request, $event, $actor, $context);

        return $row;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function mirror(GdprRequest $request, string $event, ?Authenticatable $actor, array $context): void
    {
        if (!function_exists('activity') || !class_exists('Asignua\FilamentActivityLogPlus\ActivityLogPlusServiceProvider')) {
            return;
        }

        try {
            $logger = activity('gdpr')
                ->performedOn($request)
                ->event($event)
                ->withProperties(['request' => $request->ulid] + $context);

            if ($actor !== null) {
                $logger->causedBy($actor);
            }

            $logger->log('gdpr.'.$event);
        } catch (Throwable $e) {
            // The audit mirror must never break the process it observes; our own log row is already written.
            report($e);
        }
    }
}
