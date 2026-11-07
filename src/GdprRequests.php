<?php

declare(strict_types=1);

namespace Asignua\FilamentGdprRequests;

use Asignua\FilamentGdprRequests\DataMap\PersonalDataRegistry;
use Asignua\FilamentGdprRequests\Enums\RequestChannel;
use Asignua\FilamentGdprRequests\Enums\RequestType;
use Asignua\FilamentGdprRequests\Models\GdprRequest;
use Asignua\FilamentGdprRequests\Services\RequestService;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Entry points for the host application's own code (a public form, a mail handler, a service provider).
 */
final class GdprRequests
{
    private static ?Closure $adminsUsing = null;

    /**
     * Records a request that came in through your own channel (a public form, an e-mail parser). The request is
     * ALWAYS created unverified: export and erasure stay locked until a staff member records the identity check.
     *
     * @param class-string<Model>|null $subjectModel link the request to a mapped model...
     * @param int|string|null          $subjectId    ...by its key, only if you already know it for certain
     */
    public static function submit(
        RequestType $type,
        ?string $email = null,
        ?string $name = null,
        ?string $message = null,
        RequestChannel $channel = RequestChannel::Web,
        ?string $locale = null,
        ?string $subjectModel = null,
        string|int|null $subjectId = null,
    ): GdprRequest {
        return app(RequestService::class)->create([
            'type' => $type,
            'channel' => $channel,
            'email' => $email,
            'name' => $name,
            'message' => $message,
            'locale' => $locale,
            'subject_model' => $subjectModel,
            'subject_id' => $subjectId,
        ]);
    }

    /**
     * Who is told when a request is overdue: fn (): iterable of notifiable users. Without it only the assignee
     * is reminded (the plugin cannot guess who your administrators are).
     *
     * @param Closure(): iterable<object> $resolver
     */
    public static function notifyAdminsUsing(Closure $resolver): void
    {
        self::$adminsUsing = $resolver;
    }

    public static function hasAdminResolver(): bool
    {
        return self::$adminsUsing !== null;
    }

    /**
     * @return iterable<object>
     */
    public static function admins(): iterable
    {
        return self::$adminsUsing === null ? [] : (self::$adminsUsing)();
    }

    /**
     * Declares that a table referencing a mapped table holds no personal data of the subject.
     */
    public static function acknowledge(string $table, string $reason): void
    {
        app(PersonalDataRegistry::class)->acknowledge($table, $reason);
    }

    public static function reset(): void
    {
        self::$adminsUsing = null;
    }
}
