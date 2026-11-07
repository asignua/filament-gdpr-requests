<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests\Services;

use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Asignua\FilamentGdprRequests\Notifications\RequesterNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Stage mails to the requester. Off unless `requester_mail.enabled`; needs an address on the request.
 */
final class RequesterMail
{
    public static function send(GdprRequest $request, string $stage): void
    {
        if (!(bool) config('filament-gdpr-requests.requester_mail.enabled', false) || $request->subject_email === null) {
            return;
        }

        $notification = new RequesterNotification($stage, $request->reference(), $request->due_at->toDateString());

        if ($request->locale !== null) {
            $notification->locale($request->locale);
        }

        Notification::route('mail', $request->subject_email)->notify($notification);
    }
}
